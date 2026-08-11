<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

/**
 * Proves a delivery came from us and is recent.
 *
 * The timestamp is signed together with the body, not sent beside it. Signing
 * only the body produces a signature that stays valid forever: anyone who
 * captures one request can replay it a year later and the receiver cannot tell
 * the difference. Including the timestamp in the signed material lets the
 * receiver refuse anything old, and moving the timestamp to dodge that check
 * breaks the signature.
 *
 * The header shape follows Stripe's, because the people integrating with this
 * have usually written the verifier for it once already.
 */
final class WebhookSignature
{
    public const string HEADER = 'X-Signature';

    /**
     * How old a delivery may be before a receiver should refuse it.
     */
    public const int TOLERANCE = 300;

    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, self::compute($payload, $secret, $timestamp));
    }

    public static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * The receiver's half, kept here so the documentation can point at code
     * that is actually exercised by the tests.
     */
    public static function verify(string $header, string $payload, string $secret, int $now, int $tolerance = self::TOLERANCE): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, '');
            $parts[$key] = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }

        // hash_equals, e não ==: a comparação comum sai no primeiro byte
        // diferente, e o tempo até sair conta quantos bytes acertaram.
        return hash_equals(self::compute($payload, $secret, $timestamp), $parts['v1']);
    }
}
