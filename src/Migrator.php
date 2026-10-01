<?php

namespace Joredierckx\KirbyS3Sync;

/**
 * Bulk-migrates existing files to S3 (used by the `s3:migrate` command).
 *
 * Per file:
 * - already on S3 at the expected key → skip
 * - on S3 under an old key            → move (copy, verify, update s3_key, delete old)
 * - local file is a placeholder       → skip
 * - otherwise                         → Uploader::uploadAndReplace()
 */
class Migrator
{
    public static function run(iterable $pages, bool $dryRun, ?callable $out = null): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $result   = ['done' => [], 'skipped' => [], 'errors' => [], 'lines' => []];
        $uploaded = [];

        $say = function (string $line) use (&$result, $out) {
            $result['lines'][] = $line;
            if ($out) $out($line);
        };

        $say($dryRun ? 'DRY RUN — nothing will be changed' : 'LIVE — uploading/moving files in S3');

        foreach ($pages as $page) {
            foreach ($page->files() as $file) {
                $id          = $file->id();
                $expectedKey = Uploader::key($file);
                $currentKey  = $file->content()->get('s3_key')->value();

                try {
                    // Already on S3 with the right key
                    if ($currentKey === $expectedKey) {
                        $result['skipped'][] = $id;
                        $say("skip (already on S3): {$id}");
                        continue;
                    }

                    // On S3 under an old key (e.g. page moved/renamed): move it
                    if ($currentKey) {
                        $say(($dryRun ? 'would move' : 'move') . ": {$currentKey} → {$expectedKey}");
                        if (!$dryRun) {
                            static::move($file, $currentKey, $expectedKey, $say);
                        }
                        $result['done'][] = $id;
                        Log::info('migrate ' . ($dryRun ? 'would move' : 'moved'), Log::file($file, ['from' => $currentKey, 'to' => $expectedKey]));
                        continue;
                    }

                    // 1x1 placeholder (68 bytes) without s3_key: nothing to upload
                    if (filesize($file->root()) < 100) {
                        $result['skipped'][] = $id;
                        $say("skip (looks like placeholder): {$id}");
                        continue;
                    }

                    $say(($dryRun ? 'would upload' : 'upload') . ": {$id} → {$expectedKey}");
                    if (!$dryRun) {
                        Uploader::uploadAndReplace($file);
                        $uploaded[] = $file;
                    }
                    $result['done'][] = $id;
                    Log::info('migrate ' . ($dryRun ? 'would upload' : 'uploaded'), Log::file($file, ['key' => $expectedKey]));
                } catch (\Throwable $t) {
                    $result['errors'][] = ['file' => $id, 'error' => $t->getMessage()];
                    $say("failed: {$id}: {$t->getMessage()}");
                    Log::error('migrate failed', Log::file($file, ['error' => $t->getMessage()]));
                }
            }
        }

        // CDN JSON waits 1s per file, so fetch it after the response is sent
        if ($uploaded && option('s3.json', false)) {
            DeferSync::deferSync(function () use ($uploaded) {
                foreach ($uploaded as $file) {
                    try {
                        SyncJson::syncCdnJson($file);
                    } catch (\Throwable $t) {
                        Log::error('migrate json fetch failed', Log::file($file, ['error' => $t->getMessage()]));
                    }
                }
            });
            $say('CDN json for ' . count($uploaded) . ' file(s) will be fetched in the background');
        }

        $result['summary'] = sprintf(
            '%sDone: %d · skipped: %d · errors: %d',
            $dryRun ? '(dry run) ' : '',
            count($result['done']),
            count($result['skipped']),
            count($result['errors'])
        );
        $result['lines'][] = $result['summary'];

        return $result;
    }

    protected static function move($file, string $from, string $to, callable $say): void
    {
        $client = Client::make();
        $bucket = option('s3.bucket');

        $client->copyObject([
            'Bucket'     => $bucket,
            'CopySource' => $bucket . '/' . $from,
            'Key'        => $to,
            'ACL'        => 'public-read',
        ]);

        if (!$client->doesObjectExist($bucket, $to)) {
            throw new \Exception('Verification failed after copy');
        }

        // Update s3_key first: it's the source of truth we care most about
        $file->update(['s3_key' => $to]);

        // If this fails we only leak a stale copy in S3 (harmless, cleanable later)
        try {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $from]);
        } catch (\Throwable $t) {
            $say("  moved, but failed to delete old key {$from}: {$t->getMessage()}");
        }
    }
}
