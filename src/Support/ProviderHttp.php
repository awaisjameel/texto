<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class ProviderHttp
{
    public const TWILIO_BASE_URLS = [
        'messaging' => 'https://api.twilio.com/2010-04-01',
        'conversations' => 'https://conversations.twilio.com/v1',
        'content' => 'https://content.twilio.com/v1',
    ];

    public const TELNYX_BASE_URL = 'https://api.telnyx.com/v2/';

    public const WHATSAPP_BASE_URL = 'https://graph.facebook.com/v26.0/';

    /** @param array<string, mixed> $config */
    public static function twilio(string $api, array $config): PendingRequest
    {
        $settings = new Configuration($config);
        $client = self::base($settings, self::twilioBaseUrl($api, $config));
        $sid = $settings->text('account_sid');
        $token = $settings->text('auth_token');
        if ($sid !== '' && $token !== '') {
            $client = $client->withBasicAuth($sid, $token);
        }

        return $api === 'content' ? $client->acceptJson()->asJson() : $client->asForm();
    }

    /** @param array<string, mixed> $config */
    public static function twilioBaseUrl(string $api, array $config): string
    {
        $default = self::TWILIO_BASE_URLS[$api] ?? throw new InvalidArgumentException('Unknown Twilio API.');

        return rtrim((new Configuration($config))->text('base_urls.'.$api, $default), '/');
    }

    /** @param array<string, mixed> $config */
    public static function telnyx(array $config): PendingRequest
    {
        $settings = new Configuration($config);

        return self::base($settings, $settings->text('base_url', self::TELNYX_BASE_URL))
            ->withToken($settings->text('api_key'))->acceptJson()->asJson();
    }

    /** @param array<string, mixed> $config */
    public static function whatsapp(array $config): PendingRequest
    {
        $settings = new Configuration($config);

        return self::base($settings, $settings->text('base_url', self::WHATSAPP_BASE_URL))
            ->withToken($settings->text('access_token'))->acceptJson()->asJson();
    }

    private static function base(Configuration $settings, string $url): PendingRequest
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string(parse_url($url, PHP_URL_HOST))) {
            throw new InvalidArgumentException('Provider base URL must be an absolute HTTP(S) URL.');
        }
        $timeout = $settings->integer('timeout', 30, 1);

        return Http::timeout($timeout)->connectTimeout($timeout)->withoutRedirecting()->baseUrl(rtrim($url, '/').'/');
    }
}
