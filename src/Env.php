<?php

namespace Joredierckx\KirbyS3Sync;

/**
 * Local = localhost, *.local, *.test, *.ddev.site or a loopback IP
 * (Kirby's own Environment::isLocal()).
 *
 * On a local host the sync is bypassed (dry run, only logged)
 * unless s3.localhost is set to true.
 */
class Env
{
    public static function isLocal(): bool
    {
        return kirby()->environment()->isLocal();
    }

    public static function bypass(): bool
    {
        return static::isLocal() && !option('s3.localhost', false);
    }
}
