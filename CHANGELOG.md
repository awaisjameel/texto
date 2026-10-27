# Changelog

All notable changes to `texto` will be documented in this file.

## 4.0.0 - 2026-10-27

- Enforce Pint, PHPStan, strict manifest validation and dependency audits on pull requests and pushes; consolidate duplicate/automatic-formatting workflows.

### Modernization and fixes

- Support maintained PHP 8.2-8.5 and Laravel 12/13; update dependencies, remove unused tooling, and enforce PHPStan level 8 without a baseline.
- Encrypt effective per-message credential snapshots and queue payloads. Add the nullable `driver_config` migration and hidden encrypted model cast; polling keeps the original account configuration.
- Isolate all provider adapters and endpoint settings per sender. Extend driver-manager/repository contracts with optional effective-configuration arguments.
- Execute queued sends once per ledger row, upgrade failures in place, preserve named email addresses, and mark unknown failed-job outcomes ambiguous. Add execution locks and bounded retry validation.
- Correct Telnyx Ed25519 headers and `timestamp|body` signing; preserve Twilio raw form/query/JSON signatures and validate malformed webhook boundaries.
- Preserve Conversations attachments or fail clearly; remove uncertain retries and undocumented template payload fallback. Paginate lists safely and manage only the exact matching webhook URL.
- Implement documented aggregate/detailed delivery receipts: any recipient success suffices, all must fail before a failure is reported. Support signed `onDeliveryUpdated` callbacks with the same policy.
- Prevent stale status writes and polling starvation. Support no-storage webhooks without database access; preserve inbound media metadata.
- Make installer/test-send failures observable, retain existing published files by default, ship provider guides, and update WhatsApp's default to Graph API v26.0.
- Expand queue, signature, protocol, media, scheduling, command and delivery regression coverage. See MIGRATION.md for required deployment and custom-interface updates.

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
- Conversations created for a definitively rejected send are deleted again (best effort); unknown outcomes are preserved to avoid deleting an accepted message or resending it.

### Breaking

- `MessageSenderInterface::send()`, `SentMessageResult::$to`/`$from`, and `WebhookProcessingResult::$to`/`$from`/`inbound()` are now typed to the new `AddressInterface` instead of `PhoneNumber` (which implements it). Custom senders registered via `DriverManager::extend()` must update their `send()` signature to `AddressInterface`; code reading `$result->to->e164` should prefer the portable `$result->to->value()` (the `e164` property still exists on `PhoneNumber`). Phone-only drivers must reject non-phone addresses (see the `ExpectsPhoneNumbers` trait).
- The provider API adapters (`TwilioMessagingApiInterface`, `TwilioConversationsApiInterface`, `TwilioContentApiInterface`, `TelnyxMessagingApiInterface`, `WhatsappApiInterface`) are no longer bound in the service container. Boot-time singletons captured credentials once and kept serving them under per-send `driver_config` overrides. Senders now construct adapters from their own configuration, and each adapter authenticates HTTP calls with its own credentials. Inject custom or fake adapters through the sender constructors instead, e.g. `new TwilioSender($config, $fakeMessagingApi)`.
