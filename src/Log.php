<?php

namespace Joredierckx\KirbyS3Sync;
use Joredierckx\KirbyS3Sync\Env;

use Kirby\Filesystem\F;

/**
 * Writes to site/logs/s3-sync.log.
 *
 * info() only logs on a local host (localhost, *.test, *.local, …),
 * error() always logs and also goes to PHP's error_log.
 */
class Log
{
    public static function info(string $message, array $context = []): void
    {
        if (!Env::isLocal()) return;
        static::write('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        error_log('S3 ' . $message . ' ' . static::encode($context));
        static::write('ERROR', $message, $context);
    }

    // Common context for a Kirby file: filename + page id
    public static function file($file, array $extra = []): array
    {
        return [
            'file' => $file->filename(),
            'page' => $file->page() ? $file->page()->id() : 'unknown',
        ] + $extra;
    }

    protected static function write(string $level, string $message, array $context): void
    {
        try {
            $root = kirby()->root('logs') ?? kirby()->root('site') . '/logs';
            $line = sprintf('[%s] %s %s %s', date('Y-m-d H:i:s'), $level, $message, static::encode($context));
            F::append($root . '/s3-sync.log', rtrim($line) . PHP_EOL);
        } catch (\Throwable) {
            // logging must never break an upload
        }
    }

    protected static function encode(array $context): string
    {
        if (!$context) return '';

        // Keep long values (like s3_json) readable in the log
        foreach ($context as $k => $v) {
            if (is_string($v) && strlen($v) > 300) {
                $context[$k] = substr($v, 0, 300) . '…';
            }
        }

        return json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
