<?php

namespace Joredierckx\KirbyS3Sync;

/**
 * Fetches Cloudflare's image metadata (format=json) for an uploaded file
 * and stores it as s3_json. Meant to run deferred, after the upload.
 */
class SyncJson
{
    protected static function fetchCdnJson(string $key): ?string
    {
        if (!$cdn = option('s3.cdn')) {
            return null;
        }

        sleep(1); // give Cloudflare a moment to process
        $response = @file_get_contents($cdn . '/cdn-cgi/image/format=json/' . $key);
        if (!$response) {
            return null;
        }

        json_decode($response);
        return json_last_error() === JSON_ERROR_NONE ? $response : null;
    }

    public static function syncCdnJson($file): void
    {
        if (!option('s3.json', false)) return;

        // Reload the file: the $file the hook received still has the old content (no s3_key, width or height)
        $fresh = $file->page()?->file($file->filename());
        if (!$fresh) return;

        $key = $fresh->content()->get('s3_key')->value();
        if (!$key) return; // the upload failed, so there's nothing on the CDN to fetch

        $json = static::fetchCdnJson($key);
        if (!$json) return;

        $fresh->update(['s3_json' => $json]);
    }
}
