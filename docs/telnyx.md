# Telnyx with Texto

Set `TEXTO_DRIVER=telnyx`, `TELNYX_API_KEY`, `TELNYX_MESSAGING_PROFILE_ID`, and `TELNYX_FROM_NUMBER`. The default endpoint is Telnyx Messaging API v2. `TELNYX_HTTP_TIMEOUT` defaults to 30 seconds; an explicit `TELNYX_BASE_URL` is captured per sender with the effective credentials.

`Texto::send()` accepts phone recipients, body, `media_urls`, and metadata. A per-message `metadata['webhook_url']` becomes the Telnyx callback destination. Enable fallback polling if needed; snapshots ensure a later credential change does not poll a different tenant's account. HTTP failures, malformed message responses, and missing message IDs are rejected rather than recorded as successful sends.

Point provider POST webhooks to `/texto/webhook/telnyx`. Set `TELNYX_PUBLIC_KEY` to the base64 Ed25519 public verification key from Telnyx and enable PHP Sodium. `TELNYX_WEBHOOK_SECRET` is a legacy fallback variable for that same public key; it is neither an API key nor an HMAC secret.

Texto validates `telnyx-signature-ed25519` and `telnyx-timestamp` over the exact bytes `timestamp|raw-body`. Timestamp tolerance defaults to 300 seconds (`TELNYX_WEBHOOK_TOLERANCE`); keep your server clock synchronized. Do not decode/re-encode the JSON before verification. Inbound media objects are normalized to URL strings and retained in metadata. Provider IDs allow retried inbound events to be deduplicated; only authentic successful requests consume the package webhook rate-limit bucket.

Consult current Telnyx documentation for registration, delivery event schemas, media restrictions, throughput, retries and account pricing: [receiving webhooks](https://developers.telnyx.com/docs/development/api-fundamentals/webhooks/receiving-webhooks), [messaging webhooks](https://developers.telnyx.com/docs/messaging/messages/receiving-webhooks), and [PHP webhook verification](https://developers.telnyx.com/docs/development/sdk/php/webhooks). No fixed provider price or account limit is embedded in this guide.
