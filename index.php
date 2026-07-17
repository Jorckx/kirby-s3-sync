<?php

use Kirby\Cms\App as Kirby;

require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/Uploader.php';

Kirby::plugin('joredierckx/kirby-s3-sync', [
    'options' => [
        'active' => false,
    ],
    'blueprints' => [
      'fields/s3meta' => __DIR__ . '/blueprints/fields/s3meta.yml',
    ],
    'hooks' => require __DIR__ . '/src/Hooks.php',
    'api'   => require __DIR__ . '/src/Api.php',
    'components' => require __DIR__ . '/src/Components.php',
]);
