<?php

namespace Joredierckx\KirbyS3Sync;
use Kirby\Cms\Pages;

/**
 * Brings files back from S3 to the local Kirby content folder.
 *
 * Per file:
 * - no s3_key                          → skip (never migrated)
 * - local file is a real file (>=100B) → skip (nothing to restore)
 * - otherwise                          → download, verify size, replace placeholder,
 *                                        clear s3_* fields, optionally delete remote
 */
class Restorer
{
    public static function run(Pages $pages, bool $dryRun, bool $deleteRemote = false, ?callable $out = null): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $result = ['done' => [], 'skipped' => [], 'errors' => [], 'lines' => []];

        $say = function (string $line) use (&$result, $out) {
            $result['lines'][] = $line;
            if ($out) $out($line);
        };

        $total   = Migrator::countFiles($pages);
        $current = 0;
        $client  = $dryRun ? null : Client::make();
        $bucket  = option('s3.bucket');

        foreach ($pages as $page) {
            foreach ($page->files() as $file) {
                $current++;
                $tag = "[{$current}/{$total}] ";
                $id  = $file->id();
                $key = $file->content()->get('s3_key')->value();

                try {
                    if (!$key) {
                        $result['skipped'][] = $id;
                        $say($tag . "skip (not on S3): {$id}");
                        continue;
                    }

                    if (filesize($file->root()) >= 100) {
                        $result['skipped'][] = $id;
                        $say($tag . "skip (local file is already a real file): {$id}");
                        continue;
                    }

                    $say($tag . ($dryRun ? 'would restore' : 'restore') . ": {$key} → {$file->root()}");

                    if (!$dryRun) {
                        static::restore($client, $bucket, $file, $key, $deleteRemote, $say);
                    }

                    $result['done'][] = $id;
                    Log::info('restore ' . ($dryRun ? 'would restore' : 'restored'), Log::file($file, ['key' => $key]));
                } catch (\Throwable $t) {
                    $result['errors'][] = ['file' => $id, 'error' => $t->getMessage()];
                    $say($tag . "failed: {$id}: {$t->getMessage()}");
                    Log::error('restore failed', Log::file($file, ['error' => $t->getMessage()]));
                }
            }
        }

        $result['summary'] = sprintf(
            '%sRestored: %d · skipped: %d · errors: %d',
            $dryRun ? '(dry run) ' : '',
            count($result['done']),
            count($result['skipped']),
            count($result['errors'])
        );
        $result['lines'][] = $result['summary'];

        return $result;
    }

    protected static function restore($client, string $bucket, $file, string $key, bool $deleteRemote, callable $say): void
    {
        // 1. Download to a temp file next to the target (same filesystem → atomic rename)
        $tmp = $file->root() . '.s3tmp';

        try {
            $obj = $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $tmp]);

            // 2. Verify before touching the placeholder
            $expected = (int)($obj['ContentLength'] ?? 0);
            if (!is_file($tmp) || $expected === 0 || filesize($tmp) !== $expected) {
                throw new \Exception('Verification failed after download');
            }

            // 3. Replace the placeholder
            if (!rename($tmp, $file->root())) {
                throw new \Exception('Could not write local file');
            }
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }

        // 4. Drop stale media copies/thumbnails
        $file->unpublish(true);

        // 5. Clear S3 metadata (after the local file is safe)
        $file->update([
            's3_key'    => '',
            's3_json'   => '',
            's3_width'  => '',
            's3_height' => '',
        ]);

        // 6. Optionally delete remote. Failure only leaves a harmless orphan.
        if ($deleteRemote) {
            try {
                $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            } catch (\Throwable $t) {
                $say("  restored, but failed to delete remote {$key}: {$t->getMessage()}");
            }
        }
    }
}
