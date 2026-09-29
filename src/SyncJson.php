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
            Log::info('json skipped: no s3.cdn', ['key' => $key]);
            return null;
        }

        sleep(1); // give Cloudflare a moment to process
        $url      = $cdn . '/cdn-cgi/image/format=json/' . $key;
        $response = @file_get_contents($url);
        if (!$response) {
            Log::info('json skipped: empty response', ['url' => $url]);
            return null;
        }

        json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::info('json skipped: invalid JSON', ['url' => $url, 'response' => $response]);
            return null;
        }

        return $response;
    }

    public static function syncCdnJson($file): void
    {
        if (!option('s3.json', false)) {
            Log::info('json skipped: s3.json off', Log::file($file));
            return;
        }

        // Reload the file: the $file the hook received still has the old content (no s3_key, width or height)
        $fresh = $file->page()?->file($file->filename());
        if (!$fresh) {
            Log::info('json skipped: file not found on reload', Log::file($file));
            return;
        }

        $key = $fresh->content()->get('s3_key')->value();
        if (!$key) {
            // the upload failed, so there's nothing on the CDN to fetch
            Log::info('json skipped: no s3_key', Log::file($file));
            return;
        }

        $json = static::fetchCdnJson($key);
        if (!$json) return;

        $fresh->update(['s3_json' => $json]);
        Log::info('s3_json saved', Log::file($file, ['key' => $key, 's3_json' => $json]));
    }
}
