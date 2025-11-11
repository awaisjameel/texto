### Key Points

-   Twilio's Messaging API enables sending SMS and MMS messages via REST endpoints, supporting features like status callbacks, media attachments, and content templates for enhanced delivery.
-   The Conversations API allows managing multi-participant threads, including adding SMS/MMS participants, sending messages with media or templates, and configuring scoped webhooks for event handling.
-   Content Templates API facilitates programmatic creation and management of rich content templates, which can include variables and media, optimized for channels like SMS/MMS, WhatsApp, and more.

### Twilio API Authentication

All Twilio APIs use HTTP Basic Authentication. Use your Account SID as the username and Auth Token as the password. In PHP/Laravel, implement this via Guzzle or Laravel's HTTP client for secure requests. Best practices include using environment variables for credentials, handling rate limits (e.g., 1 request per second per endpoint), and validating webhook signatures for incoming requests.

### Overview of Covered APIs

-   **Messaging API**: Focuses on direct SMS/MMS sending with options for segmentation, scheduling, and error handling.
-   **Conversations API**: Builds threaded conversations, supports SMS/MMS integration, and includes webhook scoping for real-time events.
-   **Content Templates API**: Manages reusable templates with variables and media, ideal for compliant, dynamic MMS content.

For implementation in PHP/Laravel, use Laravel's `Illuminate\Support\Facades\Http` facade (which wraps Guzzle) to make authenticated requests without the Twilio PHP SDK.

---

### Comprehensive Twilio API Documentation for Coding Agents

This documentation provides an in-depth reference for Twilio's Messaging, Conversations, and Content Templates APIs, tailored for coding agents building applications in PHP/Laravel. It emphasizes raw HTTP requests using Laravel's HTTP client (powered by Guzzle) to avoid SDK dependencies. All examples assume you have your Twilio Account SID and Auth Token stored in environment variables (e.g., via `.env` file: `TWILIO_ACCOUNT_SID=ACxxx` and `TWILIO_AUTH_TOKEN=your_token`). Authentication is handled via Basic Auth in each request.

The base URL for most endpoints is `https://api.twilio.com/2010-04-01` for Messaging, `https://conversations.twilio.com/v1` for Conversations, and `https://content.twilio.com/v1` for Content Templates. Always handle errors by checking HTTP status codes (e.g., 201 for creation, 200 for fetch, 204 for deletion) and parsing JSON responses for `error_code` and `error_message` fields.

#### 1. Messaging API: Sending SMS/MMS

The Messaging API handles outbound and inbound SMS/MMS via the Message resource. Messages can include text (SMS), media (MMS), or content templates. Status transitions (e.g., queued → sent → delivered) are tracked, with optional callbacks.

##### Key Concepts

-   **Message SID**: Unique identifier starting with `SM` (SMS) or `MM` (MMS).
-   **Segmentation**: Long SMS (>160 GSM-7 chars) splits into segments; use `sendAsMms` for single MMS delivery.
-   **Media Handling**: Up to 10 media URLs per MMS; supported formats include JPEG/PNG/GIF (up to 5MB).
-   **Content Templates**: Use `contentSid` for pre-approved rich content (see Content Templates API).
-   **Error Handling**: Common codes include 30001 (queue overflow), 30003 (unreachable), 30005 (invalid number). Retry failed messages with exponential backoff.
-   **Best Practices**: Use Messaging Services for high-volume sending; enable smart encoding for Unicode; shorten URLs if configured.

##### Endpoints and Operations

###### Create a Message (Send SMS/MMS)

-   **Method**: POST
-   **URL**: `/Accounts/{AccountSid}/Messages.json`
-   **Parameters** (application/x-www-form-urlencoded):

| Parameter           | Type                                     | Required                                   | Description                                                                        |
| ------------------- | ---------------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------- |
| To                  | string (E.164 or channel address)        | Yes                                        | Recipient phone number or address (e.g., `+15558675310`, `whatsapp:+15558675310`). |
| From                | string (E.164, alphanumeric, short code) | Yes (if no MessagingServiceSid)            | Sender identifier (e.g., `+15017122661`).                                          |
| MessagingServiceSid | SID<MG>                                  | Yes (if no From)                           | Messaging Service SID for pooled sending. Pattern: `^MG[0-9a-fA-F]{32}$`.          |
| Body                | string                                   | Conditional (if no MediaUrl or ContentSid) | Message text (up to 1600 chars).                                                   |
| MediaUrl            | array[string<uri>]                       | Conditional (if MMS)                       | URLs to media (up to 10; e.g., `https://example.com/image.jpg`).                   |
| ContentSid          | SID<HX>                                  | Conditional                                | Content Template SID for rich content. Pattern: `^HX[0-9a-fA-F]{32}$`.             |
| ContentVariables    | string (JSON)                            | Optional                                   | Variables for template (e.g., `{"1": "John"}`).                                    |
| StatusCallback      | string<uri>                              | Optional                                   | URL for status updates (POST with MessageStatus, ErrorCode).                       |
| ValidityPeriod      | integer (1-36000)                        | Optional (default: 36000)                  | Queue time in seconds.                                                             |
| SmartEncoded        | boolean                                  | Optional                                   | Replace Unicode with GSM-7 equivalents.                                            |
| ScheduleType        | enum (`fixed`)                           | Optional                                   | For scheduling.                                                                    |
| SendAt              | string<date-time> (ISO 8601)             | Optional                                   | Scheduled send time.                                                               |
| SendAsMms           | boolean                                  | Optional                                   | Force MMS delivery.                                                                |
| RiskCheck           | enum (`enable`, `disable`)               | Optional                                   | Enable risk checks.                                                                |

-   **PHP/Laravel Example** (Send SMS):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_ACCOUNT_SID') . '/Messages.json', [
            'To' => '+15558675310',
            'From' => '+15017122661',
            'Body' => 'Hello from Twilio!',
        ]);

    if ($response->successful()) {
        $message = $response->json();
        // Handle $message['sid'], $message['status'], etc.
    } else {
        // Handle error: $response->json()['error_message']
    }
    ```

-   **PHP/Laravel Example** (Send MMS with Media):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_ACCOUNT_SID') . '/Messages.json', [
            'To' => '+15558675310',
            'From' => '+15017122661',
            'Body' => 'Check this out!',
            'MediaUrl' => ['https://example.com/image.jpg', 'https://example.com/video.mp4'],
        ]);
    ```

-   **Response Example** (JSON, 201 Created):
    ```json
    {
        "sid": "SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "to": "+15558675310",
        "from": "+15017122661",
        "body": "Hello from Twilio!",
        "status": "queued",
        "num_segments": "1",
        "num_media": "0",
        "date_created": "Thu, 24 Aug 2023 05:01:45 +0000",
        "uri": "/2010-04-01/Accounts/ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/Messages/SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json"
    }
    ```

###### Fetch a Message

-   **Method**: GET
-   **URL**: `/Accounts/{AccountSid}/Messages/{Sid}.json`
-   **Parameters**: None (path: Sid required, pattern `^(SM|MM)[0-9a-fA-F]{32}$`).

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->get('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_ACCOUNT_SID') . '/Messages/SMxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.json');

    if ($response->successful()) {
        $message = $response->json();
        // Access $message['status'], $message['error_code'], etc.
    }
    ```

-   **Response Example** (JSON, 200 OK): Similar to create, with updated status and price.

###### List Messages

-   **Method**: GET
-   **URL**: `/Accounts/{AccountSid}/Messages.json`
-   **Parameters** (query string):

    -   To / From: Filter by phone.
    -   DateSent / DateSentBefore / DateSentAfter: Date filters (YYYY-MM-DD or inequalities).
    -   PageSize: 1-1000 (default 50).

-   **PHP/Laravel Example** (Filtered by Date):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->get('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_ACCOUNT_SID') . '/Messages.json', [
            'DateSent' => '2023-08-24',
            'From' => '+15017122661',
            'PageSize' => 20,
        ]);

    if ($response->successful()) {
        $messages = $response->json()['messages'];
        // Paginate with 'next_page_uri'
    }
    ```

-   **Response Example** (JSON, 200 OK): Array of messages with pagination metadata.

###### Update a Message (Redact or Cancel)

-   **Method**: POST
-   **URL**: `/Accounts/{AccountSid}/Messages/{Sid}.json`
-   **Parameters**:

    -   Body: string (set to "" to redact).
    -   Status: enum (`canceled`) for scheduled messages.

-   **PHP/Laravel Example** (Redact Body):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_ACCOUNT_SID') . '/Messages/SMxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.json', [
            'Body' => '',
        ]);
    ```

###### Delete a Message

-   **Method**: DELETE
-   **URL**: `/Accounts/{AccountSid}/Messages/{Sid}.json`
-   **Parameters**: None.
-   **Response**: 204 No Content on success.

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->delete('https://api.twilio.com/2010-04-01/Accounts/' . env('TWILIO_ACCOUNT_SID') . '/Messages/SMxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.json');
    ```

##### Status Callbacks

Twilio POSTs to your `StatusCallback` URL with parameters like `MessageStatus` (e.g., `delivered`), `ErrorCode`. In Laravel, handle via a route/controller:

```php
// routes/web.php
Route::post('/twilio/status', 'TwilioController@handleStatus');

// TwilioController.php
public function handleStatus(Request $request) {
    // Validate signature (use Twilio\RequestValidator)
    $status = $request->input('MessageStatus');
    // Log or process status
}
```

#### 2. Conversations API: Sending SMS/MMS in Conversations and Scoped Webhooks

The Conversations API manages multi-channel threads, integrating SMS/MMS participants. Conversations contain Participants and Messages. Scoped webhooks trigger on conversation-specific events.

##### Key Concepts

-   **Conversation SID**: `CHxxx`.
-   **Participant SID**: `MBxxx` for messaging (SMS/MMS).
-   **Message SID**: `IMxxx`.
-   **SMS/MMS Integration**: Add participants with `messagingBinding.address` (recipient) and `proxyAddress` (Twilio number).
-   **Scoped Webhooks**: Up to 5 per conversation; targets like `webhook`, `studio`, `trigger`.
-   **Error Handling**: 404 for non-existent resources; validate inputs to avoid 400 errors.
-   **Best Practices**: Use timers for auto-closing inactive conversations; replay messages with `replayAfter` in webhooks.

##### Endpoints for Conversations

###### Create a Conversation

-   **Method**: POST
-   **URL**: `/Conversations`
-   **Parameters**:

| Parameter              | Type                                  | Required                   | Description                              |
| ---------------------- | ------------------------------------- | -------------------------- | ---------------------------------------- |
| friendlyName           | string (≤256 chars)                   | Optional                   | Human-readable name.                     |
| uniqueName             | string                                | Optional                   | Unique identifier.                       |
| messagingServiceSid    | SID<MG>                               | Optional                   | Link to Messaging Service.               |
| attributes             | string (JSON)                         | Optional                   | Metadata (e.g., `{"topic": "support"}`). |
| state                  | enum (`active`, `inactive`, `closed`) | Optional (default: active) | Conversation state.                      |
| timers.inactive        | string (ISO8601 duration)             | Optional (min: 1m)         | Inactive timer.                          |
| timers.closed          | string (ISO8601 duration)             | Optional (min: 10m)        | Closed timer.                            |
| bindings.email.address | string                                | Optional                   | Default email for outbound.              |

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://conversations.twilio.com/v1/Conversations', [
            'FriendlyName' => 'Support Chat',
            'MessagingServiceSid' => 'MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);

    if ($response->successful()) {
        $conversation = $response->json();
        // Use $conversation['sid']
    }
    ```

-   **Response Example** (JSON, 201 Created): Includes `sid`, `state`, `timers`, etc.

###### Add Participant (for SMS/MMS)

-   **Method**: POST
-   **URL**: `/Conversations/{ConversationSid}/Participants`
-   **Parameters**:

| Parameter                         | Type           | Required               | Description                             |
| --------------------------------- | -------------- | ---------------------- | --------------------------------------- |
| messagingBinding.address          | string (E.164) | Yes for SMS/MMS        | Recipient phone (e.g., `+15558675310`). |
| messagingBinding.proxyAddress     | string (E.164) | Yes for SMS/MMS        | Twilio number (e.g., `+15017122661`).   |
| messagingBinding.projectedAddress | string         | Optional for Group MMS | Masking number.                         |
| attributes                        | string (JSON)  | Optional               | Metadata.                               |
| roleSid                           | SID<RL>        | Optional               | Role assignment.                        |

-   **PHP/Laravel Example** (Add SMS Participant):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://conversations.twilio.com/v1/Conversations/CHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Participants', [
            'MessagingBinding.Address' => '+15558675310',
            'MessagingBinding.ProxyAddress' => '+15017122661',
        ]);
    ```

-   **Response Example**: Includes `sid`, `messaging_binding` with type `sms`.

###### Send Message in Conversation (SMS/MMS)

-   **Method**: POST
-   **URL**: `/Conversations/{ConversationSid}/Messages`
-   **Parameters**:

| Parameter        | Type                 | Required                   | Description                         |
| ---------------- | -------------------- | -------------------------- | ----------------------------------- |
| body             | string (≤1600 chars) | Conditional                | Text content.                       |
| mediaSid         | SID<ME>              | Conditional for MMS        | Media SID for attachments.          |
| contentSid       | SID<HX>              | Conditional                | Template SID.                       |
| contentVariables | string (JSON)        | Optional                   | Variables (e.g., `{"1": "Value"}`). |
| author           | string               | Optional (default: system) | Author identifier.                  |
| attributes       | string (JSON)        | Optional                   | Metadata.                           |
| subject          | string (≤256 chars)  | Optional                   | Message subject.                    |

-   **PHP/Laravel Example** (Send SMS in Conversation):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://conversations.twilio.com/v1/Conversations/CHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Messages', [
            'Author' => 'agent',
            'Body' => 'Hello, how can I help?',
        ]);
    ```

-   **PHP/Laravel Example** (Send MMS with Template):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://conversations.twilio.com/v1/Conversations/CHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Messages', [
            'ContentSid' => 'HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'ContentVariables' => json_encode(['1' => 'John']),
            'MediaSid' => 'MExxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);
    ```

-   **Response Example**: Includes `sid`, `body`, `media` array, `delivery` status.

##### Scoped Webhooks for Conversations

Scoped webhooks are conversation-specific, supporting post-events only (e.g., `onMessageAdded`).

###### Create Scoped Webhook

-   **Method**: POST
-   **URL**: `/Conversations/{ConversationSid}/Webhooks`
-   **Parameters** (configuration object):

| Parameter   | Type                                  | Required            | Description                                 |
| ----------- | ------------------------------------- | ------------------- | ------------------------------------------- |
| target      | enum (`webhook`, `studio`, `trigger`) | Yes                 | Webhook type.                               |
| url         | string<uri>                           | Optional            | Target URL for requests.                    |
| method      | enum (`GET`, `POST`)                  | Optional            | HTTP method.                                |
| filters     | array[string]                         | Optional            | Events to trigger (e.g., `onMessageAdded`). |
| triggers    | array[string]                         | Optional            | Keywords for triggers.                      |
| flowSid     | SID<FW>                               | Optional for studio | Studio flow SID.                            |
| replayAfter | integer                               | Optional            | Message index to replay from.               |

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://conversations.twilio.com/v1/Conversations/CHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Webhooks', [
            'Target' => 'webhook',
            'Configuration.Url' => 'https://yourapp.com/webhook',
            'Configuration.Method' => 'POST',
            'Configuration.Filters' => ['onMessageAdded', 'onParticipantAdded'],
        ]);
    ```

-   **Response Example**: Includes `sid`, `target`, `configuration`.

###### Fetch Scoped Webhook

-   **Method**: GET
-   **URL**: `/Conversations/{ConversationSid}/Webhooks/{Sid}`
-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->get('https://conversations.twilio.com/v1/Conversations/CHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Webhooks/WHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    ```

###### List Scoped Webhooks

-   **Method**: GET
-   **URL**: `/Conversations/{ConversationSid}/Webhooks`
-   **Parameters**: PageSize (1-5), Page, PageToken.

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->get('https://conversations.twilio.com/v1/Conversations/CHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/Webhooks', [
            'PageSize' => 5,
        ]);
    ```

###### Update Scoped Webhook

-   **Method**: POST
-   **URL**: `/Conversations/{ConversationSid}/Webhooks/{Sid}`
-   **Parameters**: Same as create (update configuration).

###### Delete Scoped Webhook

-   **Method**: DELETE
-   **URL**: `/Conversations/{ConversationSid}/Webhooks/{Sid}`

#### 3. Content Templates API: Creating and Managing Templates

The Content Templates API allows programmatic handling of templates for rich messaging, including MMS with media, variables for personalization, and approval for channels like WhatsApp. Templates support types like `twilio/text`, `twilio/media`, `twilio/quick-reply`, `twilio/card` (for MMS-like carousels).

##### Key Concepts

-   **Template SID**: `HXxxx`.
-   **Variables**: Placeholders `{{1}}`, `{{2}}` with defaults; override at send time.
-   **Types**: Define channel-specific content (e.g., `twilio/media` for MMS with images/videos).
-   **Approval**: Required for WhatsApp; submit via `/ApprovalRequests/whatsapp`.
-   **Limits**: Unlimited via API; WhatsApp: 6000 approved per account.
-   **Best Practices**: Use defaults for approval; search templates with filters; delete unused templates to avoid clutter.

##### Endpoints and Operations

###### Create a Template

-   **Method**: POST
-   **URL**: `/Content`
-   **Parameters** (JSON body):

| Parameter    | Type               | Required               | Description                                                  |
| ------------ | ------------------ | ---------------------- | ------------------------------------------------------------ |
| types        | object             | Yes                    | Content types (e.g., {"twilio/text": {"body": "Hi {{1}}"}}). |
| language     | string (ISO 639-1) | Optional (default: en) | Language code.                                               |
| friendlyName | string             | Optional               | Descriptive name.                                            |
| variables    | object             | Optional               | Defaults (e.g., {"1": "John"}).                              |

-   **PHP/Laravel Example** (Create MMS Template with Media):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->post('https://content.twilio.com/v1/Content', [
            'friendly_name' => 'promo_mms',
            'language' => 'en',
            'variables' => ['1' => 'Customer'],
            'types' => [
                'twilio/media' => [
                    'body' => 'Hi {{1}}, check our promo!',
                    'media' => ['https://example.com/image.jpg'],
                ],
                'twilio/text' => [
                    'body' => 'Hi {{1}}, check our promo!',
                ],
            ],
        ]);

    if ($response->successful()) {
        $template = $response->json();
        // Use $template['sid']
    }
    ```

-   **Response Example** (JSON, 201 Created): Includes `sid`, `types`, `variables`, `links` for approvals.

###### Fetch a Template

-   **Method**: GET
-   **URL**: `/Content/{ContentSid}`

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->get('https://content.twilio.com/v1/Content/HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    ```

-   **Response Example**: Full template details.

###### List Templates

-   **Method**: GET
-   **URL**: `/Content`
-   **Parameters**: PageSize (up to 500), PageToken.

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->get('https://content.twilio.com/v1/Content', [
            'PageSize' => 100,
        ]);
    ```

-   **Response Example**: Paginated `contents` array.

###### List Templates with Approvals (v1/ContentAndApprovals)

-   **Method**: GET
-   **URL**: `/ContentAndApprovals`
-   **Parameters**: PageSize, PageToken.
-   Similar PHP example as above.

###### List Legacy WhatsApp Mappings

-   **Method**: GET
-   **URL**: `/LegacyContent`
-   Similar to list endpoints.

###### Update a Template

-   **Method**: POST (or PUT in some contexts, but POST for partial updates)
-   **URL**: `/Content/{ContentSid}`
-   **Parameters**: Same as create (override fields).

-   **PHP/Laravel Example** (Update Variables):

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->post('https://content.twilio.com/v1/Content/HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', [
            'variables' => ['1' => 'Updated Customer'],
        ]);
    ```

###### Delete a Template

-   **Method**: DELETE
-   **URL**: `/Content/{ContentSid}`
-   **Response**: 204 No Content.

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->delete('https://content.twilio.com/v1/Content/HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    ```

###### Submit for Approval (e.g., WhatsApp)

-   **Method**: POST
-   **URL**: `/Content/{ContentSid}/ApprovalRequests/whatsapp`
-   **Parameters**: name (string), category (enum like `UTILITY`).

-   **PHP/Laravel Example**:

    ```php
    use Illuminate\Support\Facades\Http;

    $response = Http::withBasicAuth(env('TWILIO_ACCOUNT_SID'), env('TWILIO_AUTH_TOKEN'))
        ->asForm()
        ->post('https://content.twilio.com/v1/Content/HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/ApprovalRequests/whatsapp', [
            'name' => 'promo_template',
            'category' => 'UTILITY',
        ]);
    ```

###### Fetch Approval Status

-   **Method**: GET
-   **URL**: `/Content/{ContentSid}/ApprovalRequests`

##### Template Types Table

| Type               | Description                    | Example Fields                                                     |
| ------------------ | ------------------------------ | ------------------------------------------------------------------ |
| twilio/text        | Plain text fallback.           | body: string                                                       |
| twilio/media       | MMS with attachments.          | body: string, media: array[uri]                                    |
| twilio/quick-reply | Buttons for interaction.       | body: string, actions: array[{title, id}]                          |
| twilio/card        | Carousel for MMS/rich content. | title: string, subtitle: string, media: array[uri], actions: array |
| twilio/location    | Share coordinates.             | latitude: number, longitude: number, label: string                 |

For sending templates, reference `contentSid` in Messaging or Conversations API calls, with `contentVariables` for overrides.

This covers all aspects for integration. Monitor Twilio Console for usage and errors.

### Key Citations

-   [Twilio Messaging API: Message Resource](https://www.twilio.com/docs/messaging/api/message-resource)
-   [Twilio Conversations API Overview](https://www.twilio.com/docs/conversations/api)
-   [Twilio Conversation Resource](https://www.twilio.com/docs/conversations/api/conversation-resource)
-   [Twilio Conversation Participant Resource](https://www.twilio.com/docs/conversations/api/conversation-participant-resource)
-   [Twilio Conversation Message Resource](https://www.twilio.com/docs/conversations/api/conversation-message-resource)
-   [Twilio Conversation Scoped Webhook Resource](https://www.twilio.com/docs/conversations/api/conversation-scoped-webhook-resource)
-   [Twilio Content API Resources](https://www.twilio.com/docs/content/content-api-resources)
-   [Twilio Content API Quickstart](https://www.twilio.com/docs/content/create-and-send-your-first-content-api-template)
-   [Twilio API Requests and Best Practices](https://www.twilio.com/docs/usage/requests-to-twilio)
-   [HTTP Methods with cURL](https://www.twilio.com/en-us/blog/http-methods-requests-curl)
-   [Consuming APIs in Laravel with Guzzle](https://www.honeybadger.io/blog/laravel-guzzle/)
