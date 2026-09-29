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

        $file->update([
            's3_key'    => $key,
            's3_width'  => $s3Width,
            's3_height' => $s3Height,
        ]);

        $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        file_put_contents($file->root(), $placeholder);

        // MEDIA ONLY UNPUBLISH
        // Drop any media copies/thumbs Kirby published from the original before the sync finished (all hash versions share the media token)
        $file->unpublish(true);
        // This uses Kirby's own FileActions::unpublish($onlyMedia = true) → Media::unpublish(), which globs <mediaToken>-* and removes every hash version. That covers the orphaned old-hash folder and all the thumbnails in it.
        // true = media only. It leaves locks and the UUID cache alone.
        // It runs last, so a failed upload still leaves everything as it was.
    }

    public static function key($file): string
    {
        return option('s3.sitename') . '/' . $file->page()->id() . '/assets/' . $file->type() . 's/' . $file->filename();
    }

}
