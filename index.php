<?php

use Aws\S3\S3Client;
use Kirby\Cms\App as Kirby;

function s3Client(): S3Client {
  return new S3Client([
    'version'     => 'latest',
    'region'      => option('s3.region'),
    'endpoint'    => option('s3.endpoint'),
    'credentials' => [
      'key'    => option('s3.key'),
      'secret' => option('s3.secret'),
    ],
  ]);
}

Kirby::plugin('studio-dier/s3-bucket', [
  'blueprints' => [
    'fields/s3fields' => __DIR__ . '/blueprints/fields/s3fields.yml',
    // 'sections/s3upload' => __DIR__ . '/blueprints/sections/s3upload.yml'
  ],
  'hooks' => require __DIR__ . '/src/hooks.php',
  'components' => require __DIR__ . '/src/components.php',
  'api' => require __DIR__ . '/src/api.php',
]);
