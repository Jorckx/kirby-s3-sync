<?php
// I. LOCATION
// place this script in the `scripts` folder at the root of your Kirby installation
//
// II. HOW TO RUN
// Run with: php scripts/migrate-to-r2.php
// Dry run:  php scripts/migrate-to-r2.php --dry-run

// ——————————————————————————————————————————————————————————
// 1. Load Composer's autoloader first
require __DIR__ . '/../vendor/autoload.php'; // Composer autoloader first

// 2. Load .env first so env() is available when config.php runs
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
if (!function_exists('env')) {
  function env(string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value !== false ? $value : $default;
  }
}

// 3. Load Kirby's bootstrap
require __DIR__ . '/../vendor/getkirby/cms/bootstrap.php';

// 4. Create Kirby's App instance
$kirby = new Kirby\Cms\App([
  'roots' => [
    'index' => __DIR__ . '/..',
    'content' => __DIR__ . '/../storage/content',
  ],
  'options' => [
    'url' => 'https://admin.studiodier.com', // tells Kirby which config file to load
  ]
]);

// 5. Impersonate the 'kirby' user
$kirby->impersonate('kirby');

// 6. Parse command line arguments
$dryRun  = in_array('--dry-run', $argv);
$errors  = [];
$skipped = [];
$done    = [];

// 7. Output the migration status
echo $dryRun ? "🔍 DRY RUN — nothing will be changed\n\n" : "🚀 Starting migration...\n\n";

// 8. Load environment variables (secrets)
$s3Key    = $_ENV['S3_KEY'] ?? getenv('S3_KEY');
$s3Secret = $_ENV['S3_SECRET'] ?? getenv('S3_SECRET');

// 9 Required config — must all be set before we touch R2
$required = ['s3.sitename', 's3.bucket', 's3.region', 's3.endpoint'];
$missing  = [];
foreach ($required as $key) {
  if (empty(option($key))) {
    $missing[] = $key;
  }
}
if ($missing) {
  echo "✗ Missing required config: " . implode(', ', $missing) . "\n";
  exit(1);
}
$siteName = option('s3.sitename');

// 10. Create S3 client
$client = new Aws\S3\S3Client([
  'version'     => 'latest',
  'region'      => option('s3.region'),
  'endpoint'    => option('s3.endpoint'),
  'credentials' => [
    'key'    => $s3Key,
    'secret' => $s3Secret,
  ],
]);



// 11. Output the number of pages to be migrated
echo "Pages found: " . $kirby->site()->index()->count() . "\n";

// 12. ask cli if user wants to iterate over pages and files or migrate speciphic pages
echo "Do you want to migrate all pages and files? (y/n): ";
$migrateAll = readline();
if ($migrateAll !== 'y') {
    echo "Enter the page ID to migrate: ";
    $pageId = readline();
	// single page-id
    $page = $kirby->page($pageId);
    if (!$page) {
        echo "Page not found.\n";
        exit;
    }
    $pages = new \Kirby\Cms\Pages([$page]);
} else {
	// list all pages
    $pages = $kirby->site()->index();
}

// 13 Show what's about to happen and require explicit confirmation
$fileCount = 0;
foreach ($pages as $p) {
    $fileCount += count($p->files());
}
echo "\n--- About to migrate ---\n";
if ($migrateAll === 'y') {
    echo "Scope: ALL pages\n";
} else {
    echo "Scope: single page → {$page->id()}\n";
}
echo "Pages: " . count($pages) . "\n";
echo "Files: {$fileCount}\n";
echo "Mode:  " . ($dryRun ? "DRY RUN (no changes)" : "LIVE (will upload/move/delete in R2)") . "\n";
echo "expected R2 key: \n" . $siteName . '/<page-id>/assets/<file-type>/<filename>' . "\n";

echo "\nProceed? (y/n): ";
$confirm = readline();
if ($confirm !== 'y') {
    echo "Aborted.\n";
    exit;
}
echo "\n";

// 14. Iterate over pages and files
$current = 0;
foreach ($pages as $page) {
  foreach ($page->files() as $file) {
    $current++;
    echo "[{$current}/{$fileCount}] ";

  	$expectedKey = $siteName . '/' . $file->page()->id() . '/assets/' . $file->type() . 's/' . $file->filename();

    // 14.1 Check if file is already on R2
    if ($file->content()->get('s3_key')->isNotEmpty()) {
      $currentKey = $file->content()->get('s3_key')->value();

      // Check if the path matches the expected structure
      if ($currentKey === $expectedKey) {
        $skipped[] = $file->id();
        echo "⏭  Skipping (already on R2 with correct path): {$file->id()}\n";
        continue;
      }

      // Path has changed — move the file in R2
      echo "🔄 Moving (path changed): {$currentKey} → {$expectedKey}\n";

      if (!$dryRun) {
        try {
          // Copy to new location
          $client->copyObject([
            'Bucket'     => option('s3.bucket'),
            'CopySource' => option('s3.bucket') . '/' . $currentKey,
            'Key'        => $expectedKey,
            'ACL'        => 'public-read',
          ]);

          // Verify copy succeeded
          if (!$client->doesObjectExist(option('s3.bucket'), $expectedKey)) {
            throw new Exception('Verification failed after copy');
          }

          // Update metadata FIRST — this is the source of truth we care most about protecting
          $file->update(['s3_key' => $expectedKey]);

          // Delete old location — if this fails, we just leak a stale copy in R2 (harmless, cleanable later)
          try {
            $client->deleteObject([
              'Bucket' => option('s3.bucket'),
              'Key'    => $currentKey,
            ]);
          } catch (Exception $e) {
            echo "   ⚠ Moved, but failed to delete old key ({$currentKey}): {$e->getMessage()}\n";
          }

          $done[] = $file->id();
          echo "   ✓ Moved\n";

        } catch (Exception $e) {
          $errors[] = ['file' => $file->id(), 'error' => $e->getMessage()];
          echo "   ✗ Failed: {$e->getMessage()} / try again\n";
        }
      } else {
        $done[] = $file->id();
      }

      continue;
    }

    // 14.2 Skip placeholder files (1x1px = 68 bytes)
    if (filesize($file->root()) < 100) {
      $skipped[] = $file->id();
      echo "⏭  Skipping (looks like placeholder): {$file->id()}\n";
      continue;
    }

    echo "📤 " . ($dryRun ? '[would upload] ' : '') . "{$file->id()} → {$expectedKey}\n";

    if ($dryRun) {
      $done[] = $file->id();
      continue;
    }

    try {
      // Upload
      $client->putObject([
        'Bucket'     => option('s3.bucket'),
        'Key'        => $expectedKey,
        'SourceFile' => $file->root(),
        'ACL'        => 'public-read',
      ]);

      // Verify
      if (!$client->doesObjectExist(option('s3.bucket'), $expectedKey)) {
        throw new Exception('Verification failed after upload');
      }

      // After successful upload, fetch provider-specific image info if a CDN endpoint is configured
      // (this is currently only meaningful for Cloudflare/R2's image-info endpoint)
      $s3Json = null;
      if ($cdn = option('s3.cdn')) {
        $jsonUrl = $cdn . '/cdn-cgi/image/format=json/' . $expectedKey;
        $response = @file_get_contents($jsonUrl);
        $s3Json = $response ?: null;
      }

      $file->update([
        's3_key'  => $expectedKey,
        's3_json' => $s3Json,
      ]);

      // Replace with placeholder
      $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
      file_put_contents($file->root(), $placeholder);

      $done[] = $file->id();
      echo "   ✓ Done\n";

    } catch (Exception $e) {
      $errors[] = ['file' => $file->id(), 'error' => $e->getMessage()];
      echo "   ✗ Failed: {$e->getMessage()} / try again \n";
      // Original file untouched, migration continues
    }
  }
}

// 15. Output summary
echo "\n--- Summary ---\n";
echo "✓ Migrated: " . count($done)    . "\n";
echo "⏭  Skipped:  " . count($skipped) . "\n";
echo "✗ Errors:   " . count($errors)  . "\n";

// 16. Output failed files (if any)
if ($errors) {
  echo "\nFailed files:\n";
  foreach ($errors as $e) {
    echo "  - {$e['file']}: {$e['error']}\n";
  }
}
