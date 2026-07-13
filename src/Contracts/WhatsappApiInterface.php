<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

interface WhatsappApiInterface
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $payload): array;

    /** @return array<string, mixed> */
    public function getMediaUrl(string $mediaId): array;

    /** @return array<string, mixed> */
    public function markAsRead(string $wamid): array;
}
