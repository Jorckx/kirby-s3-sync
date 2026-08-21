<?php

use Joredierckx\KirbyS3Sync\Uploader;
use Joredierckx\KirbyS3Sync\Client;

/**
 * Runs the upload after the current HTTP response has already been
 * sent to the browser, so the Panel never waits on S3/CDN latency.
 *
 * Falls back to running inline (old behaviour) on SAPIs that don't
 * support fastcgi_finish_request(), e.g. the built-in dev server or CLI.
 */
function s3syncDeferred(\Closure $work): void
{
    ignore_user_abort(true);

    register_shutdown_function(function () use ($work) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $work();
    });
}

// Register the hooks
return [
    'file.create:after' => function ($file) {
        if (!option('s3.active')) return;
        s3syncDeferred(function () use ($file){
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
        });
    },

    'file.replace:after' => function ($newFile) {
        if (!option('s3.active')) return;
        s3syncDeferred(function () use ($file){
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
        });
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
