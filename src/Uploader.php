<?php

namespace Joredierckx\KirbyS3Sync;

class Uploader
{
    public static function uploadAndReplace($file): void
    {
        $client = Client::make();
        $key    = static::key($file);

        $result = $client->putObject([
            'Bucket'     => option('s3.bucket'),
            'Key'        => $key,
            'SourceFile' => $file->root(),
            'ACL'        => 'public-read',
        ]);

        if (!$result || !$result->get('ObjectURL')) {
            throw new \Exception('Upload returned no confirmation');
        }

        if (!$client->doesObjectExist(option('s3.bucket'), $key)) {
            throw new \Exception('File not found in S3 after upload');
        }

        // Width/height read locally — reliable, no dependency on CDN timing
        $size     = @getimagesize($file->root());
        $s3Width  = $size ? $size[0] : null;
        $s3Height = $size ? $size[1] : null;

        // s3_json is bonus metadata only (format, original file size, etc.)
        // — never required for width/height, which are already set above
        $s3Json = static::fetchCdnJson($key);

        $file->update([
            's3_key'    => $key,
            's3_json'   => $s3Json,
            's3_width'  => $s3Width,
            's3_height' => $s3Height,
        ]);

        $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        file_put_contents($file->root(), $placeholder);
    }

    public static function key($file): string
    {
        return option('s3.sitename') . '/' . $file->page()->id() . '/assets/' . $file->type() . 's/' . $file->filename();
    }

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

        $decoded = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $response : null;
    }
}
