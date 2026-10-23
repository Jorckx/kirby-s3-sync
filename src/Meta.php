<?php

namespace Joredierckx\KirbyS3Sync;

/**
 * Reads and writes the s3_* file metadata.
 *
 * The S3 state belongs to the physical file, not to a translation, so on a
 * multi-language site it always lives in the default language.
 */
class Meta
{
    public static function get($file, string $field)
    {
        $code = static::language();
        return $file->content($code)->get($field)->value();
    }

    public static function set($file, array $data)
    {
        return $file->update($data, static::language());
    }

    private static function language(): ?string
    {
        $kirby = kirby();
        return $kirby->multilang() ? $kirby->defaultLanguage()->code() : null;
    }
}
