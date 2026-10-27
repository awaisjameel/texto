# WhatsApp Cloud API with Texto

Set `TEXTO_DRIVER=whatsapp`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, and `WHATSAPP_VERIFY_TOKEN`. `WHATSAPP_FROM_NUMBER` populates the local record; Meta derives the actual sender from the phone-number ID. `WHATSAPP_HTTP_TIMEOUT` defaults to 30 seconds.

The default Graph base is `https://graph.facebook.com/v26.0`. This version was checked against Meta's maintained [official PHP Business SDK configuration](https://github.com/facebook/facebook-php-business-sdk/blob/main/src/FacebookAds/ApiConfig.php); direct Meta documentation was rate limited during the upgrade. Use `WHATSAPP_BASE_URL` only as an intentional API-version override and review Meta's current changelog/permissions before changing it.

```php
use Awaisjameel\Texto\Facades\Texto;

Texto::send('+14155552671', 'Your order is ready.', ['driver' => 'whatsapp']);
Texto::send('+14155552671', '', [
    'driver' => 'whatsapp',
    'metadata' => ['template' => [
        'name' => 'order_update',
        'language' => 'en_US',
        'components' => [],
    ]],
]);
```

The application must have the required permissions, an authorized sender/recipient workflow and an approved template where required. Texto does not create or approve Meta templates. Template metadata takes precedence over free text/media. For a media send, pass exactly one `media_urls` entry. Texto infers image/video/audio/sticker/document from the URL path extension; unrecognized extensions use document. Audio and sticker sends reject captions. Captions and media types remain subject to Meta rules. Unsupported multiple attachments fail clearly.

Configure `/texto/webhook/whatsapp` as the callback URL. GET subscription verification checks the `hub.mode`, `hub.verify_token` and `hub.challenge` values. POST batches require `X-Hub-Signature-256`, computed over the untouched raw body using the app secret. Do not replace this with the access token or Texto's email shared secret. Messages/statuses are normalized from each batch and inbound provider IDs deduplicate stored deliveries. WhatsApp tracking uses callbacks rather than provider polling.

Provider documentation: [Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/), [webhooks](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks), and [Graph versions](https://developers.facebook.com/docs/graph-api/changelog/versions). Verify current service-window, template, media, registration and pricing rules with Meta for your account.
