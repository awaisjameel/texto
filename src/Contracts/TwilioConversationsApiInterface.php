<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

/**
 * Adapter contract for Twilio Conversations API operations.
 */
interface TwilioConversationsApiInterface
{
    /**
     * Create a new conversation and return raw response.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createConversation(string $friendlyName, array $params = []): array;

    /**
     * Add a participant (SMS) to a conversation.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function addParticipant(string $conversationSid, string $address, string $proxyAddress, array $params = []): array;

    /**
     * Send message inside a conversation (supports body or contentSid + contentVariables).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendConversationMessage(string $conversationSid, array $payload): array;

    /**
     * Attach webhook to conversation (optionally clearing existing).
     *
     * @param  list<string>  $filters
     * @param  list<string>  $triggers
     * @return array<string, mixed>|null
     */
    public function attachWebhook(string $conversationSid, string $url, array $filters = ['onMessageAdded', 'onMessageUpdated'], array $triggers = []): ?array;

    /**
     * Fetch a single conversation message by SID.
     *
     * @return array<string, mixed>
     */
    public function fetchConversationMessage(string $conversationSid, string $messageSid): array;

    /** Delete a conversation (best-effort). */
    public function deleteConversation(string $conversationSid): bool;
}
