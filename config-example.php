<?php

return [
    's3' => [
        'active'    => '<boolean>', // whether to use R2 bucket for file storage
        'key'       => '<your-r2-access-key-id>',
        'secret'    => '<your-r2-secret-access-key>',
        'bucket'    => '<your-bucket-name>',
        'sitename'  => '<website-name>', // never use '.' since this will be used as a subdomain
        'region'    => 'auto or <your-region>',  // R2 always uses 'auto'
        'endpoint'  => 'https://<your-account-id>.r2.cloudflarestorage.com',
        'cdn'       => '<custom-domain> or <dev-domain>', // your R2 public domain
        'dimensions' => [
          'enabled'        => true,
          'width_field'    => 's3_width',   // name of the field to store width in
          'height_field'   => 's3_height',  // name of the field to store height in
        ],
    ],
];
