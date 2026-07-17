<?php

namespace Joredierckx\KirbyS3Sync;

use Aws\S3\S3Client;

class Client
{
    protected static ?S3Client $instance = null;

    public static function make(): S3Client
    {
        if (static::$instance !== null) {
            return static::$instance;
        }

        $required = ['s3.bucket', 's3.region', 's3.endpoint', 's3.sitename'];
        foreach ($required as $key) {
            if (empty(option($key))) {
                throw new \Exception("Missing required config: {$key}");
            }
        }

        return static::$instance = new S3Client([
            'version'     => 'latest',
            'region'      => option('s3.region'),
            'endpoint'    => option('s3.endpoint'),
            'credentials' => [
                'key'    => env('S3_KEY'),
                'secret' => env('S3_SECRET'),
            ],
        ]);
    }
}
