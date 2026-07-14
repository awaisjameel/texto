# Changelog

All notable changes to `texto` will be documented in this file.

## Unreleased

### Added

- First-class `whatsapp` driver backed by Meta's WhatsApp Cloud API, including templates, media sends, Graph API exceptions, and signed batch webhooks.
- Conversation SID caching for Twilio Conversations sends: repeat sends to the same `(from, to)` pair reuse the cached conversation and skip the setup API calls (`TWILIO_CONVERSATION_CACHE_TTL`, default 7 days, `0` disables). Stale cache entries (closed/deleted conversations) fall back to a fresh setup automatically.
- `metadata['webhook_url']` on the Twilio classic Messages path is now passed to Twilio as the per-message `StatusCallback`, mirroring the Telnyx driver.

### Changed

- Twilio webhook signature validation now signs only POST body parameters (plus the full URL including query string), so webhook URLs with query strings validate correctly. Configure `TrustProxies` behind TLS-terminating proxies.
- Twilio Conversations `onMessageAdded` events authored by the configured `from_number` (echoes of Texto's own outbound messages) are recognized and intentionally ignored instead of being recorded as inbound; `WebhookHandlerInterface::handle()` may now return `null` for such events.
- Conversation-scoped webhook attachment sends `Configuration.Filters`/`Configuration.Triggers` as repeated form fields, which Twilio requires (comma-joined values were silently ignored).
- Conversations created for a send that ultimately fails are deleted again (best effort), so failed sends no longer leak orphaned conversations.

### Breaking

- The provider API adapters (`TwilioMessagingApiInterface`, `TwilioConversationsApiInterface`, `TwilioContentApiInterface`, `TelnyxMessagingApiInterface`, `WhatsappApiInterface`) are no longer bound in the service container. Boot-time singletons captured credentials once and kept serving them under per-send `driver_config` overrides. Senders now construct adapters from their own configuration, and each adapter authenticates HTTP calls with its own credentials. Inject custom or fake adapters through the sender constructors instead, e.g. `new TwilioSender($config, $fakeMessagingApi)`.
