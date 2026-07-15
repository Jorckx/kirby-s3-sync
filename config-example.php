<?php

return [
    's3' => [
        'active'    => '<boolean>', // whether to use R2 bucket for file storage
        'key'       => '<your-r2-access-key-id>',
        'secret'    => '<your-r2-secret-access-key>',
        'bucket'    => '<your-bucket-name>',
        'site'      => '<website-name>-com', // never use '.' since this will be used as a subdomain
        'region'    => 'auto or <your-region>',  // R2 always uses 'auto'
        'endpoint'  => 'https://<your-account-id>.r2.cloudflarestorage.com',
        'cdn'       => '<custom-domain> or <dev-domain>', // your R2 public domain
        // 'metadata'  => [
        //     // Store dimensions before replacing
        //     $imageSize = getimagesize($file->root());
        //     's3_width'  => $imageSize ? $imageSize[0] : null,
        //     's3_height' => $imageSize ? $imageSize[1] : null,
        // ],
    ],
];
