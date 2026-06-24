<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

/**
 * Lightweight Twilio signature validator (replaces SDK RequestValidator).
 * Algorithm: HMAC-SHA1(authToken, url + concatenated POST params (alpha key order key+value)).
 * Header: X-Twilio-Signature (Base64 encoded digest)
 */
class TwilioSignatureValidator
{
    public static function validate(string $authToken, string $fullUrl, array $params, string $provided): bool
    {
        ksort($params);
        $data = $fullUrl;
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $item) {
                    $data .= $k.$item;
                }
            } else {
                $data .= $k.$v;
            }
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        if (function_exists('hash_equals')) {
            return hash_equals($expected, $provided);
        }

        return $expected === $provided;
    }
}
