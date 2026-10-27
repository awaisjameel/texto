<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

/** Twilio's documented URL/form HMAC and JSON bodySHA256 verification contract. */
final class TwilioSignatureValidator
{
    /** @param array<array-key, mixed>|string $params Raw JSON or form parameters. */
    public static function validate(string $authToken, string $fullUrl, array|string $params, string $provided): bool
    {
        if ($authToken === '' || $provided === '') {
            return false;
        }
        $url = parse_url($fullUrl);
        if ($url === false || ! isset($url['scheme'], $url['host'])) {
            return false;
        }
        if (is_string($params)) {
            parse_str($url['query'] ?? '', $query);
            $hash = $query['bodySHA256'] ?? null;
            if (! is_string($hash) || ! hash_equals(hash('sha256', $params), $hash)) {
                return false;
            }
            $params = [];
        }
        ksort($params);
        $suffix = '';
        foreach ($params as $key => $value) {
            $items = is_array($value) ? $value : [$value];
            $normalized = [];
            foreach ($items as $item) {
                if (! is_scalar($item) && $item !== null) {
                    return false;
                }
                $normalized[] = (string) $item;
            }
            $normalized = array_unique($normalized);
            sort($normalized);
            foreach ($normalized as $item) {
                $suffix .= $key.$item;
            }
        }
        // Twilio's maintained SDK checks with and without a port because its
        // backend signature generation has historically used both representations.
        $base = $url['scheme'].'://'.$url['host'];
        $tail = ($url['path'] ?? '').(isset($url['query']) ? '?'.$url['query'] : '');
        $withPort = $base.':'.($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80)).$tail;
        $withoutPort = $base.$tail;
        foreach (array_unique([$fullUrl, $withPort, $withoutPort]) as $candidate) {
            if (hash_equals(base64_encode(hash_hmac('sha1', $candidate.$suffix, $authToken, true)), $provided)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string|list<string>> */
    public static function formParameters(string $body): array
    {
        $result = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if (array_key_exists($name, $result)) {
                $previous = $result[$name];
                $result[$name] = [...(is_array($previous) ? $previous : [$previous]), $value];
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
