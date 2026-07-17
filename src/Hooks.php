<?php

use Joredierckx\KirbyS3Sync\Uploader;
use Joredierckx\KirbyS3Sync\Client;

return [
    'file.create:after' => function ($file) {
        if (!option('s3.active')) return;
        try {
            Uploader::uploadAndReplace($file);
        } catch (Exception $e) {
            error_log('S3 upload failed: ' . $e->getMessage());
        }
    },

    'file.replace:after' => function ($file) {
        if (!option('s3.active')) return;
        try {
            Uploader::uploadAndReplace($file);
        } catch (Exception $e) {
            error_log('S3 replace failed: ' . $e->getMessage());
        }
    },

    'file.delete:before' => function ($file) {
        if (!option('s3.active')) return;
        $key = $file->content()->get('s3_key')->value();
        if ($key) {
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
        }
    },
];
