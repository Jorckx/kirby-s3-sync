<?php
// I. HOW TO RUN
// Run with: php site/scripts/migrate-to-r2.php
// Dry run:  php site/scripts/migrate-to-r2.php --dry-run

// II. PREPARE THE ENVIRONMENT
// In a standalone CLI script, you're starting from scratch.
// bootstrap.php sets up Kirby's core, but you also need Composer's autoloader
// to make all classes (including Kirby\Cms\App) findable. the env() function is also needed, so we load it first.

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
    // Explicitly tell Kirby where to find the correct folders
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

// 9. Create S3 client
$client = new Aws\S3\S3Client([
  'version'     => 'latest',
  'region'      => option('s3.region'),
  'endpoint'    => option('s3.endpoint'),
  'credentials' => [
    'key'    => $s3Key,
    'secret' => $s3Secret,
  ],
]);

// 10. Output the number of pages to be migrated
echo "Pages found: " . $kirby->site()->index()->count() . "\n";

// 11. ask cli if user wants to iterate over pages and files or migrate speciphic pages
//
echo "Do you want to migrate all pages and files? (y/n): ";
$migrateAll = readline();
if ($migrateAll !== 'y') {
    echo "Enter the page ID to migrate: ";
    $pageId = readline();
    $pages = $kirby->site()->index()->find($pageId);
    if (!$pages) {
        echo "Page not found.\n";
        exit;
    }
    $pages = [$pages];
} else {
    $pages = $kirby->site()->index();
}

// 12. Iterate over pages and files
foreach ($pages as $page) {
  foreach ($page->files() as $file) {

    // 12.1 Skip files already on R2
    if ($file->content()->get('s3_key')->isNotEmpty()) {
      $skipped[] = $file->id();
      echo "⏭  Skipping (already on R2): {$file->id()}\n";
      continue;
    }

    // 12.2 Skip placeholder files (1x1px = 68 bytes)
    if (filesize($file->root()) < 100) {
      $skipped[] = $file->id();
      echo "⏭  Skipping (looks like placeholder): {$file->id()}\n";
      continue;
    }

    $key    = option('s3.sitename') . '/' . $file->page()->id() . '/assets/' . $file->type() .  's/' . $file->filename();
    echo "📤 " . ($dryRun ? '[would upload] ' : '') . "{$file->id()} → {$key}\n";

    if ($dryRun) {
      $done[] = $file->id();
      continue;
    }

    try {
      // Upload
      $client->putObject([
        'Bucket'     => option('s3.bucket'),
        'Key'        => $key,
        'SourceFile' => $file->root(),
        'ACL'        => 'public-read',
      ]);

      // Verify
      if (!$client->doesObjectExist(option('s3.bucket'), $key)) {
        throw new Exception('Verification failed after upload');
      }

      // Store dimensions before replacing
      $imageSize = @getimagesize($file->root());

      // Update metadata
      $file->update([
        's3_key'    => $key,
        's3_width'  => $imageSize ? $imageSize[0] : null,
        's3_height' => $imageSize ? $imageSize[1] : null,
      ]);

      // Replace with placeholder
      $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
      file_put_contents($file->root(), $placeholder);

      $done[] = $file->id();
      echo "   ✓ Done\n";

    } catch (Exception $e) {
      $errors[] = ['file' => $file->id(), 'error' => $e->getMessage()];
      echo "   ✗ Failed: {$e->getMessage()}\n";
      // Original file untouched, migration continues
    }
  }
}

echo "\n--- Summary ---\n";
echo "✓ Migrated: " . count($done)    . "\n";
echo "⏭  Skipped:  " . count($skipped) . "\n";
echo "✗ Errors:   " . count($errors)  . "\n";

if ($errors) {
  echo "\nFailed files:\n";
  foreach ($errors as $e) {
    echo "  - {$e['file']}: {$e['error']}\n";
  }
}
