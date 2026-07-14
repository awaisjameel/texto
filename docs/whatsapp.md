# WhatsApp Cloud API driver

Texto can send and receive WhatsApp messages directly through Meta's WhatsApp Cloud API. It uses Laravel's HTTP client; no third-party SDK is required.

## Setup

Create a Meta Business Portfolio and a Business-type app, add the WhatsApp product, then collect a permanent system-user token, the Phone Number ID, App Secret, and a random webhook verify token. A Meta test number can message up to five verified recipients during development.

```env
TEXTO_DRIVER=whatsapp
WHATSAPP_ACCESS_TOKEN=your-permanent-system-user-token
WHATSAPP_PHONE_NUMBER_ID=your-phone-number-id
WHATSAPP_APP_SECRET=your-app-secret
WHATSAPP_VERIFY_TOKEN=a-random-secret-value
WHATSAPP_FROM_NUMBER=+15551234567
```

`WHATSAPP_PHONE_NUMBER_ID` is the Graph API sender identity, not the phone number. `WHATSAPP_FROM_NUMBER` is only stored as the local message's `from` number.

In Meta App Dashboard, configure the callback URL as `https://your-host/texto/webhook/whatsapp`, supply `WHATSAPP_VERIFY_TOKEN`, verify it, and subscribe to the `messages` field. That field contains inbound messages and delivery statuses. The endpoint validates Meta's `X-Hub-Signature-256` signature using `WHATSAPP_APP_SECRET`, so it intentionally does not use `X-Texto-Secret`.

## Sending messages

```php
// Free-form text is allowed only during the 24-hour customer-service window.
Texto::send('+923001234567', 'Hello!', ['driver' => 'whatsapp']);

// Public HTTPS media URL. WhatsApp accepts one attachment per API request.
Texto::send('+923001234567', 'Your invoice', [
    'driver' => 'whatsapp',
    'media_urls' => ['https://example.com/invoice.pdf'],
]);

// Required outside the 24-hour window (and for business-initiated messages).
Texto::send('+923001234567', '', [
    'driver' => 'whatsapp',
    'metadata' => [
        'template' => [
            'name' => 'order_shipped',
            'language' => 'en_US',
            'components' => [[
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => 'AWB-12345']],
            ]],
        ],
    ],
]);
```

Text supports `metadata.preview_url`. Media types are inferred from URL extensions; images (`.jpg`, `.jpeg`, `.png`), videos (`.mp4`, `.3gp`), audio (`.mp3`, `.aac`, `.ogg`, `.amr`, `.m4a`), stickers (`.webp` — Meta accepts WebP only as a sticker), and documents (everything else) are supported. WhatsApp accepts one media item per API request, so pass exactly one URL to each `Texto::send()` call. Audio and sticker messages have no caption support; send their accompanying text separately.

## Webhooks and media

Inbound media is persisted as `whatsapp-media://{media-id}` rather than downloaded. Resolve it when needed by constructing the adapter with the credentials it should use (adapters are deliberately not bound in the container, so per-tenant credentials never leak between sends):

```php
use Awaisjameel\Texto\Support\WhatsappApi;

$api = new WhatsappApi(
    config('texto.whatsapp.access_token'),
    config('texto.whatsapp.phone_number_id'),
);
$media = $api->getMediaUrl($mediaId);
// $media['url'] is authenticated and expires in roughly five minutes.
```

For tests, inject a fake through the sender constructor: `new WhatsappSender($config, $fakeApi)`.

The driver receives delivery events through webhooks only; it does not poll statuses. `read` is preserved as its own terminal local status.

## Errors, limits, and pricing

Authentication failures (HTTP 401/403 or Graph code 190), bad parameters (common 400-level validation codes), missing IDs, and rate limits are exposed as typed WhatsApp API exceptions. Other Graph codes remain on `WhatsappApiException`; inspect `graphCode` for cases such as `131047` (the 24-hour window is closed) or template errors.

Free-form messages in the open customer-service window are free. Template pricing and sending limits vary by category, region, account verification, and Meta policy; check the current [Meta WhatsApp pricing documentation](https://developers.facebook.com/docs/whatsapp/pricing). Queue expensive webhook listeners so Meta receives a quick response, and increase `TEXTO_WEBHOOK_RATE_LIMIT` if your WABA bursts above the default.
