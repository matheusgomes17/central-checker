<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Support;

/**
 * Rewrites a single key in `.env`-style text.
 *
 * Pure string in, string out — no disk access, so it can be unit tested
 * without touching a real `.env` and reused by any command that needs to
 * persist a key without hand-editing the file.
 */
final class EnvFile
{
    public static function withValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.self::quote($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, $line, $contents, 1) ?? $contents;
        }

        $trimmed = rtrim($contents);

        return $trimmed === '' ? $line."\n" : $trimmed."\n".$line."\n";
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[\s#"]/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}
