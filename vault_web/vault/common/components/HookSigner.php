<?php

namespace common\components;

/**
 * Signs Vii-to-gateway hooks using timestamp + "." + raw JSON body.
 */
class HookSigner
{
    const VERSION = 'v1';

    public static function sign($secret, $timestamp, $body)
    {
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \InvalidArgumentException(
                'Hook secret must be at least 32 characters.'
            );
        }

        return self::VERSION . '=' . hash_hmac(
            'sha256',
            $timestamp . '.' . $body,
            $secret
        );
    }

    /** Verify the signature and timestamp; used by the tests. */
    public static function verify(
        $secret,
        $timestamp,
        $body,
        $header,
        $now = null,
        $toleranceSec = 300
    ) {
        $now = $now === null ? time() : $now;

        if (!ctype_digit((string)$timestamp) ||
            abs($now - (int)$timestamp) > $toleranceSec) {
            return false;
        }

        return hash_equals(
            self::sign($secret, $timestamp, $body),
            (string)$header
        );
    }
}