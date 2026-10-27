# Twilio with Texto

Configure `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and `TWILIO_FROM_NUMBER`. `TWILIO_USE_CONVERSATIONS` defaults to true; select false explicitly to use classic Messages. Sender adapters capture the effective account configuration, including per-send overrides.

## Conversations and templates

Texto creates a conversation and SMS participant, caches successful conversation SIDs by account/from/to, and uses Content templates. `TWILIO_CONVERSATION_CACHE_TTL` defaults to 604800 seconds; zero disables caching. A missing cached conversation is rebuilt, while unknown server outcomes are not blindly resent.

Set `TWILIO_SMS_TEMPLATE_SID` / `TWILIO_MMS_TEMPLATE_SID` to use existing compatible templates. Otherwise templates are found by friendly name across all pages or created with documented Content API JSON. Only the template needed for the current send is initialized. Text templates split up to 500 Unicode characters into five variables; longer text-only sends use a plain body.

Conversations MMS supports **one** media URL under `TWILIO_MMS_MEDIA_BASE_URL` (fallback Laravel `app.url`), plus a caption up to 500 characters. The MMS template must use that same fixed base plus `{{media_path}}`; nested relative paths and query encoding are preserved. Arbitrary remote hosts, multiple attachments, or a missing media template fail clearly. Choose classic Messages explicitly if your workflow requires its media capabilities. Texto never silently changes tracking APIs or drops attachments.

`TWILIO_CONVERSATION_WEBHOOK_URL`, or `metadata['webhook_url']`, attaches a conversation-scoped webhook. Only an existing exact URL match is updated. Other destinations remain untouched; changing your URL requires explicit cleanup of the old destination. History is not replayed automatically. On classic Messages, the same metadata option becomes `StatusCallback`.

## Inbound requests and delivery

Point POST callbacks to `/texto/webhook/twilio`. HMAC-SHA1 uses the full public URL and raw form values; JSON uses `bodySHA256` and the URL signature. Configure trusted proxies so the signed public URL is preserved. All form fields, including repeated values/whitespace, participate in validation. Conversations media JSON is normalized and retained in metadata; media SIDs may need separate MCS resolution by the application.

Classic status callbacks use `MessageStatus`. Conversations delivery events use `onDeliveryUpdated` and `Status`; enable that event on your service/global post-action webhook explicitly. Texto does not take ownership of those global settings. One read recipient yields `Read`; otherwise one delivered recipient yields `Delivered`. Failure requires all recipients to fail, using aggregate and individual receipts where needed. Optional polling follows the same policy and original stored account snapshot. Conversation messages are never polled through the classic Messages API.

Current provider references: [security](https://www.twilio.com/docs/usage/security), [webhook security](https://www.twilio.com/docs/usage/webhooks/webhooks-security), [Content API](https://www.twilio.com/docs/content/content-api-resources), [media templates](https://www.twilio.com/docs/content/twilio-media), [conversation webhooks](https://www.twilio.com/docs/conversations-classic/conversations-webhooks), [conversation-scoped management](https://www.twilio.com/docs/conversations-classic/api/conversation-scoped-webhook-resource), and [delivery receipts](https://www.twilio.com/docs/conversations-classic/delivery-receipts). Check account/channel limits and prices with Twilio rather than relying on copied historical tables.
