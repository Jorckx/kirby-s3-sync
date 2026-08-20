<?php

use Joredierckx\KirbyS3Sync\Uploader;
use Joredierckx\KirbyS3Sync\Client;

return [
    'file.create:after' => function ($file) {
        if (!option('s3.active')) return;
        try {
            Uploader::uploadAndReplace($file);
        } catch (\Throwable $t) {
            error_log(sprintf(
            	'S3 upload failed for %s (page: %s): %s',
             	$file->filename(),
              	$file->page() ? $file->page()->id() : 'unknown',
             	$t->getMessage()
            ));
        }
    },

    'file.replace:after' => function ($newFile) {
        if (!option('s3.active')) return;
        try {
            Uploader::uploadAndReplace($newFile);
        } catch (\Throwable $t) {
            error_log(sprintf(
            	'S3 replace failed for %s (page: %s): %s',
             	$newFile->filename(),
              	$newFile->page() ? $newFile->page()->id() : 'unknown',
             	$t->getMessage()
            ));
        }
    },

    'file.delete:before' => function ($file) {
        if (!option('s3.active')) return;
        $key = $file->content()->get('s3_key')->value();
        try {
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
        } catch (\Throwable $t) {
            error_log(sprintf(
            	'S3 delete failed for %s (page: %s): %s',
             	$file->filename(),
              	$file->page() ? $file->page()->id() : 'unknown',
             	$t->getMessage()
            ));
        }
    },
];
