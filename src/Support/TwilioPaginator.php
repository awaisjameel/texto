<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use UnexpectedValueException;

final class TwilioPaginator
{
    /** @return Generator<int, array<string, mixed>> */
    public static function records(PendingRequest $client, string $baseUrl, string $endpoint, string $collection): Generator
    {
        $prefix = rtrim($baseUrl, '/').'/';
        $next = $prefix.ltrim($endpoint, '/');
        $seen = [];
        while ($next !== null) {
            if (! str_starts_with($next, $prefix) || isset($seen[$next]) || parse_url($next, PHP_URL_FRAGMENT) !== null || preg_match('/[\x00-\x20\x7f]/', $next) === 1) {
                throw new TwilioApiException('Twilio returned an unsafe or repeated pagination URL.');
            }
            $seen[$next] = true;
            $response = $client->get($next);
            $data = TwilioResponse::read($response, 'list:'.$collection);
            $records = $data[$collection] ?? null;
            if (! is_array($records) || ! array_is_list($records)) {
                throw new TwilioApiException('Twilio returned an invalid collection.', $response->status());
            }
            foreach ($records as $record) {
                try {
                    yield JsonResponse::map($record);
                } catch (UnexpectedValueException $exception) {
                    throw new TwilioApiException('Twilio returned an invalid collection entry.', $response->status(), previous: $exception);
                }
            }
            $meta = $data['meta'] ?? [];
            $next = is_array($meta) ? ($meta['next_page_url'] ?? null) : null;
            if ($next !== null && (! is_string($next) || $next === '')) {
                throw new TwilioApiException('Twilio returned an invalid next-page URL.', $response->status());
            }
        }
    }
}
