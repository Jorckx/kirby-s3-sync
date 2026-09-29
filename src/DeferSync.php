<?php
namespace Joredierckx\KirbyS3Sync;
/**
 * Runs the upload after the current HTTP response has already been
 * sent to the browser, so the Panel never waits on S3/CDN latency.
 *
 * Falls back to running inline (old behaviour) on SAPIs that don't
 * support fastcgi_finish_request(), e.g. the built-in dev server or CLI.
 */
class DeferSync
{
    public static function deferSync(\Closure $work): void
    {
        ignore_user_abort(true);

        register_shutdown_function(function () use ($work) {
            $afterResponse = function_exists('fastcgi_finish_request');
            if ($afterResponse) {
                fastcgi_finish_request();
            }
            Log::info('deferred work start', ['after_response' => $afterResponse]);
            $work();
        });
    }
}
