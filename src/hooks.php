<?php

return [
    // After a file is created in Kirby's Panel:
    'file.create:after' => function ($file) {
        if (!option('s3.active')) return;

        // Upload the file to s3
        $client = s3Client();
        $key    = option('s3.site') . '/' . $file->page()->id() . '/' . $file->filename();

        try {
            $result = $client->putObject([
                'Bucket'     => option('s3.bucket'),
                'Key'        => $key,
                'SourceFile' => $file->root(),
                'ACL'        => 'public-read',
            ]);

            // Only proceed if upload actually worked
            if (!$result || !$result->get('ObjectURL')) {
                throw new Exception('Upload returned no confirmation');
            }

            // Verify the file is actually accessible in s3 before touching local
            $check = $client->doesObjectExist(option('s3.bucket'), $key);
            if (!$check) {
                throw new Exception('File not found in s3 after upload');
            }

            // Read real dimensions before replacing
            $imageSize = getimagesize($file->root());

            // Update metadata before replacing local file
            $file->update([
                's3_key'    => $key,
                's3_width'  => $imageSize ? $imageSize[0] : null,
                's3_height' => $imageSize ? $imageSize[1] : null,
            ]);

            // NOW replace with placeholder
            $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
            file_put_contents($file->root(), $placeholder);
        } catch (Exception $e) {
            // Log the error, do NOT replace the local file
            error_log('DO s3 upload failed: ' . $e->getMessage());
            // The original file remains untouched on disk
        }
    },
    // update s3 when a file is updated:
    'file.update:before' => function ($file) {
        if (!option('s3.active')) return;

        $key = $file->content()->get('s3_key')->value();
        if ($key) {
            $client = s3Client();
            $client->copyObject([
                'Bucket'     => option('s3.bucket'),
                'CopySource' => option('s3.bucket') . '/' . $key,
                'Key'        => $key,
            ]);
        }
    },
    // Clean up s3 when a file is deleted:
    'file.delete:before' => function ($file) {
        if (!option('s3.active')) return;

        $key = $file->content()->get('s3_key')->value();
        if ($key) {
            $client = s3Client();
            // BACKUP: Copy to archive first > later verwijderen als alles werkt
            $client->copyObject([
                'Bucket'     => option('s3.bucket'),
                'CopySource' => option('s3.bucket') . '/' . $key,
                'Key' => '_archive/' . $key,            ]);
            // Then delete original
            $client->deleteObject([
                'Bucket' => option('s3.bucket'),
                'Key'    => $key,
            ]);
        }
    },
];
