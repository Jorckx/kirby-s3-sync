<?php
return [
  'routes' => [
    [
      'pattern' => 's3-upload/(:any)/(:any)',
      'method'  => 'POST',
      'action'  => function (string $pageId, string $filename) {
        $pageId = str_replace('+', '/', $pageId);
        $page   = kirby()->page($pageId);
        $file   = $page->file($filename);

        if (!$file) {
          return ['status' => 'error', 'message' => 'File not found'];
        }

        $key    = option('s3.rootFolder') . '/' . $file->page()->id() . '/' . $file->filename();
        $client = s3Client();

        try {
          $result = $client->putObject([
            'Bucket'     => option('s3.bucket'),
            'Key'        => $key,
            'SourceFile' => $file->root(),
            'ACL'        => 'public-read',
          ]);

          $check = $client->doesObjectExist(option('s3.bucket'), $key);
          if (!$check) throw new Exception('Upload verification failed');

          $imageSize = getimagesize($file->root());

          $file->update([
            's3_key'    => $key,
            's3_width'  => $imageSize ? $imageSize[0] : null,
            's3_height' => $imageSize ? $imageSize[1] : null,
          ]);

          $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
          file_put_contents($file->root(), $placeholder);

          return ['status' => 'ok'];
        } catch (Exception $e) {
          return ['status' => 'error', 'message' => $e->getMessage()];
        }
      }
    ]
  ]
];
