<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Contracts;

/**
 * Adapter contract for Twilio Content Templates API subset used by Texto.
 */
interface TwilioContentApiInterface
{
    /**
     * Find template by friendly name; returns raw record or null
     *
     * @return array<string, mixed>|null
     */
    public function findTemplateByFriendlyName(string $friendlyName): ?array;

    /**
     * Create template from definition; returns SID
     *
     * @param  array<string, mixed>  $definition
     */
    public function createTemplate(array $definition): string;

    /**
     * Ensure template exists; create using factory if not; returns SID
     *
     * @param  callable():array<string, mixed>  $definitionFactory
     */
    public function ensureTemplate(string $friendlyName, callable $definitionFactory): string;
}
