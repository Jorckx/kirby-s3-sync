<?php

use Joredierckx\KirbyS3Sync\Uploader;

return [
    'routes' => [
        [
            'pattern' => 's3-upload/(:any)/(:any)',
            'method'  => 'POST',
            'action'  => function (string $pageId, string $filename) {
                if (!option('s3.active')) {
                    return ['status' => 'error', 'message' => 'S3 sync is not active'];
                }

                $pageId = str_replace('+', '/', $pageId);
                $page   = kirby()->page($pageId);
                $file   = $page ? $page->file($filename) : null;

                if (!$file) {
                    return ['status' => 'error', 'message' => 'File not found'];
                }

                try {
                    Uploader::uploadAndReplace($file);
                    return ['status' => 'ok'];
                } catch (Exception $e) {
                    return ['status' => 'error', 'message' => $e->getMessage()];
                }
            }
        ]
    ]
];
