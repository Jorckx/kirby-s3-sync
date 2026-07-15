<?php
// I. HOW TO RUN
// Run with: php site/scripts/update-r2-meta.php
// Dry run:  php site/scripts/update-r2-meta.php --dry-run

// 1. Load Composer's autoloader first
require __DIR__ . '/../vendor/autoload.php';

// 2. Load .env
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
    'index'   => __DIR__ . '/..',
    'content' => __DIR__ . '/../storage/content',
  ],
  'options' => [
    'url' => 'https://admin.studiodier.com',
  ]
]);

// 5. Impersonate the 'kirby' user
$kirby->impersonate('kirby');

// 6. Parse command line arguments
$dryRun  = in_array('--dry-run', $argv);
$errors  = [];
$skipped = [];
$done    = [];

echo $dryRun ? "🔍 DRY RUN — nothing will be changed\n\n" : "🚀 Starting meta update...\n\n";

// 7. Ask which pages to update
echo "Pages found: " . $kirby->site()->index()->count() . "\n";
echo "Do you want to update all pages? (y/n): ";
$updateAll = readline();
if ($updateAll !== 'y') {
  echo "Enter the page ID to update: ";
  $pageId = readline();
  $page = $kirby->page($pageId);
  if (!$page) {
    echo "Page not found.\n";
    exit;
  }
  $pages = new \Kirby\Cms\Pages([$page]);
} else {
  $pages = $kirby->site()->index();
}

// 8. Iterate over pages and files
foreach ($pages as $page) {
  foreach ($page->files() as $file) {

    // Skip files not on R2
    $s3Key = $file->content()->get('s3_key')->value();
    if (!$s3Key) {
      $skipped[] = $file->id();
      echo "⏭  Skipping (not on R2): {$file->id()}\n";
      continue;
    }

    // Skip files that already have s3_json
    if ($file->content()->get('s3_json')->isNotEmpty()) {
      $skipped[] = $file->id();
      echo "⏭  Skipping (already has s3_json): {$file->id()}\n";
      continue;
    }

    // Only fetch JSON for images
    if ($file->type() !== 'image') {
      $skipped[] = $file->id();
      echo "⏭  Skipping (not an image): {$file->id()}\n";
      continue;
    }

    $jsonUrl = option('s3.cdn') . '/cdn-cgi/image/format=json/' . $s3Key;
    echo "🔍 " . ($dryRun ? '[would fetch] ' : '') . "{$file->id()} → {$jsonUrl}\n";

    if ($dryRun) {
      $done[] = $file->id();
      continue;
    }

    try {
      $response = @file_get_contents($jsonUrl);

      if (!$response) {
        throw new Exception('Empty response from Cloudflare JSON endpoint');
      }

      // Validate it's actually JSON
      $decoded = json_decode($response, true);
      if (!$decoded || !isset($decoded['width'])) {
        throw new Exception('Invalid JSON response: ' . $response);
      }

      $file->update(['s3_json' => $response]);

      $done[] = $file->id();
      echo "   ✓ Updated (width: {$decoded['width']}, height: {$decoded['height']})\n";

    } catch (Exception $e) {
      $errors[] = ['file' => $file->id(), 'error' => $e->getMessage()];
      echo "   ✗ Failed: {$e->getMessage()}\n";
    }
  }
}

echo "\n--- Summary ---\n";
echo "✓ Updated: " . count($done)    . "\n";
echo "⏭  Skipped: " . count($skipped) . "\n";
echo "✗ Errors:  " . count($errors)  . "\n";

if ($errors) {
  echo "\nFailed files:\n";
  foreach ($errors as $e) {
    echo "  - {$e['file']}: {$e['error']}\n";
  }
}
