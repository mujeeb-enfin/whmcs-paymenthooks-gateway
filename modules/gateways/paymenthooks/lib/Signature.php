<?php
/**
 * Verifies the X-PaymentHooks-Signature header on incoming webhooks.
 *
 * Header format: comma-separated key=value pairs, e.g.
 *   t=1718880000,v1=<hex>,v1_previous=<hex during secret rotation>
 * Signed string: <t> + "." + <raw request body>
 * Algorithm: HMAC-SHA256, hex-encoded, keyed with the endpoint signing secret.
 */

namespace PaymentHooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Signature
{
    /** Reject timestamps older than this many seconds (replay window). */
    const TOLERANCE = 300;

    /**
     * @param string $rawBody   the exact bytes received (never a re-serialised body)
     * @param string $header    value of X-PaymentHooks-Signature
     * @param string $secret    the webhook signing secret
     * @return bool
     */
    public static function verify(string $rawBody, string $header, string $secret): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = self::parse($header);
        if (!isset($parts['t']) || !ctype_digit((string) $parts['t'])) {
            return false;
        }

        // Replay protection.
        $ts = (int) $parts['t'];
        if (abs(time() - $ts) > self::TOLERANCE) {
            return false;
        }

        $signed   = $parts['t'] . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signed, $secret);

        // Accept current (v1) or, during rotation, the previous secret's signature.
        foreach (['v1', 'v1_previous'] as $field) {
            if (!empty($parts[$field]) && hash_equals($expected, $parts[$field])) {
                return true;
            }
        }

        return false;
    }

    /** Parses "t=...,v1=...,v1_previous=..." into an associative array. */
    private static function parse(string $header): array
    {
        $out = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $out[trim($k)] = trim($v);
        }
        return $out;
    }
}
