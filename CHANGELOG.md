# Changelog

All notable changes to `texto` will be documented in this file.

## Unreleased

### Added

- First-class `email` driver that sends through the host application's Laravel mailer (any transport in `config/mail.php` — SMTP, SES, Resend, Postmark, log, array, ...) with zero new dependencies. Supports `subject`, `html`, `cc`, `bcc`, `reply_to`, and `attachments` (local paths and URLs) send options; `media_urls` are attached as files for parity with MMS.
- `EmailAddress` value object and `AddressInterface` contract; `Texto::send()` now accepts an email address (including `Name <user@example.com>`) as the recipient and routes by address type.
- Provider-agnostic email webhook at `/texto/webhook/email` for inbound email and delivery/engagement events (normalized JSON payload), authenticated with `TEXTO_WEBHOOK_SECRET` via the `X-Texto-Secret` header or `?secret=` query parameter; the endpoint rejects all traffic when no secret is configured. Inbound deliveries are idempotent on `message_id`.
- Email status vocabulary in `StatusMapper` (`delivered`, `opened`/`clicked` → Read, `bounced` → Failed, `soft_bounce` → Undelivered, `complained` → Delivered with raw value preserved, `deferred` → Sending, ...).
- `texto.email` configuration block (`TEXTO_EMAIL_MAILER`, `TEXTO_EMAIL_FROM_ADDRESS`, `TEXTO_EMAIL_FROM_NAME`, `TEXTO_EMAIL_DEFAULT_SUBJECT`).
- First-class `whatsapp` driver backed by Meta's WhatsApp Cloud API, including templates, media sends, Graph API exceptions, and signed batch webhooks.
- Conversation SID caching for Twilio Conversations sends: repeat sends to the same `(from, to)` pair reuse the cached conversation and skip the setup API calls (`TWILIO_CONVERSATION_CACHE_TTL`, default 7 days, `0` disables). Stale cache entries (closed/deleted conversations) fall back to a fresh setup automatically.
- `metadata['webhook_url']` on the Twilio classic Messages path is now passed to Twilio as the per-message `StatusCallback`, mirroring the Telnyx driver.

### Changed

- Twilio webhook signature validation now signs only POST body parameters (plus the full URL including query string), so webhook URLs with query strings validate correctly. Configure `TrustProxies` behind TLS-terminating proxies.
- Twilio Conversations `onMessageAdded` events authored by the configured `from_number` (echoes of Texto's own outbound messages) are recognized and intentionally ignored instead of being recorded as inbound; `WebhookHandlerInterface::handle()` may now return `null` for such events.
- Conversation-scoped webhook attachment sends `Configuration.Filters`/`Configuration.Triggers` as repeated form fields, which Twilio requires (comma-joined values were silently ignored).
- Conversations created for a send that ultimately fails are deleted again (best effort), so failed sends no longer leak orphaned conversations.

### Breaking

- `MessageSenderInterface::send()`, `SentMessageResult::$to`/`$from`, and `WebhookProcessingResult::$to`/`$from`/`inbound()` are now typed to the new `AddressInterface` instead of `PhoneNumber` (which implements it). Custom senders registered via `DriverManager::extend()` must update their `send()` signature to `AddressInterface`; code reading `$result->to->e164` should prefer the portable `$result->to->value()` (the `e164` property still exists on `PhoneNumber`). Phone-only drivers must reject non-phone addresses (see the `ExpectsPhoneNumbers` trait).
- The provider API adapters (`TwilioMessagingApiInterface`, `TwilioConversationsApiInterface`, `TwilioContentApiInterface`, `TelnyxMessagingApiInterface`, `WhatsappApiInterface`) are no longer bound in the service container. Boot-time singletons captured credentials once and kept serving them under per-send `driver_config` overrides. Senders now construct adapters from their own configuration, and each adapter authenticates HTTP calls with its own credentials. Inject custom or fake adapters through the sender constructors instead, e.g. `new TwilioSender($config, $fakeMessagingApi)`.
