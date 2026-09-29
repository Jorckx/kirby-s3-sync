<?php

use Joredierckx\KirbyS3Sync\Uploader;
use Joredierckx\KirbyS3Sync\SyncJson;
use Joredierckx\KirbyS3Sync\DeferSync;
use Joredierckx\KirbyS3Sync\Client;
use Joredierckx\KirbyS3Sync\Env;
use Joredierckx\KirbyS3Sync\Log;

// Shared by file.create:after and file.replace:after
$sync = function ($file, string $event): void {
    if (!option('s3.active')) return;

    // Localhost without s3.localhost: only log what would happen
    if (Env::bypass()) {
        Uploader::dryRun($event, $file);
        return;
    }

    Log::info($event . ' start', Log::file($file));

    // 1. Runs right away: upload, set width/height, write the placeholder, unpublish media
    try {
        Uploader::uploadAndReplace($file);
    } catch (\Throwable $t) {
        Log::error($event . ' upload failed', Log::file($file, ['error' => $t->getMessage()]));
        return; // no upload means no CDN JSON to fetch
    }

    // 2. Runs after the response is sent: fetch the CDN JSON (sleep + HTTP request)
    DeferSync::deferSync(function () use ($file, $event) {
        try {
            SyncJson::syncCdnJson($file);
        } catch (\Throwable $t) {
            Log::error($event . ' json fetch failed', Log::file($file, ['error' => $t->getMessage()]));
        }
    });
};

// Register the hooks
return [
    'file.create:after' => function ($file) use ($sync) {
        $sync($file, 'create:after');
    },

    'file.replace:after' => function ($newFile) use ($sync) {
        $sync($newFile, 'replace:after');
    },

    'file.delete:before' => function ($file) {
        if (!option('s3.active')) return;
        $key = $file->content()->get('s3_key')->value();

        if (!$key) {
            Log::info('delete:before no s3_key, nothing to delete', Log::file($file));
            return;
        }

        if (Env::bypass()) {
            Log::info('delete:before dry run', Log::file($file, [
                'key'     => $key,
                'archive' => '_archive/' . $key,
                'would'   => ['copyObject to _archive', 'deleteObject'],
            ]));
            return;
        }

        try {
            $client = Client::make();
            $client->copyObject([
                'Bucket'     => option('s3.bucket'),
                'CopySource' => option('s3.bucket') . '/' . $key,
                'Key'        => '_archive/' . $key,
            ]);
            $client->deleteObject([
                'Bucket' => option('s3.bucket'),
                'Key'    => $key,
            ]);
            Log::info('delete:before archived + deleted', Log::file($file, ['key' => $key]));
        } catch (\Throwable $t) {
            Log::error('delete:before delete failed', Log::file($file, ['key' => $key, 'error' => $t->getMessage()]));
        }
    },
];
