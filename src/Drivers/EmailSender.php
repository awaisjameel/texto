<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Mail\TextoMail;
use Awaisjameel\Texto\ValueObjects\EmailAddress;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email driver that sends through the host application's Laravel mailer, so any transport
 * the app already configured (SMTP, SES, log, array, ...) works with zero extra packages.
 *
 * Email-specific options travel in $metadata['email'] (see Texto::foldEmailOptionsIntoMetadata):
 * subject, html, cc, bcc, reply_to, attachments. $mediaUrls are attached as remote attachments
 * for parity with the MMS drivers.
 */
class EmailSender implements MessageSenderInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config) {}

    /**
     * @param  string[]  $mediaUrls
     * @param  array<string, mixed>  $metadata
     *
     * @throws TextoSendFailedException
     */
    public function send(AddressInterface $to, string $body, ?AddressInterface $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {
        $to = $this->assertEmailAddress($to, 'to');
        $from = $from !== null ? $this->assertEmailAddress($from, 'from') : $this->defaultFrom();

        $emailOptions = is_array($metadata['email'] ?? null) ? $metadata['email'] : [];

        $subject = is_string($emailOptions['subject'] ?? null) && $emailOptions['subject'] !== ''
            ? $emailOptions['subject']
            : (string) ($this->config['default_subject'] ?? 'New message');
        $html = is_string($emailOptions['html'] ?? null) && $emailOptions['html'] !== ''
            ? $emailOptions['html']
            : null;

        $mailable = new TextoMail(
            emailSubject: $subject,
            textBody: $body,
            htmlBody: $html,
            fromAddress: $from !== null ? new Address($from->address, $from->displayName) : null,
            ccAddresses: $this->toMailAddresses($emailOptions['cc'] ?? [], 'cc'),
            bccAddresses: $this->toMailAddresses($emailOptions['bcc'] ?? [], 'bcc'),
            replyToAddresses: $this->toMailAddresses($emailOptions['reply_to'] ?? [], 'reply_to'),
            attachmentSpecs: $this->normalizeAttachments($emailOptions['attachments'] ?? [], $mediaUrls),
        );

        try {
            $sent = Mail::mailer($this->mailerName())
                ->to(new Address($to->address, $to->displayName))
                ->send($mailable);
        } catch (\Throwable $e) {
            Log::error('Texto email send failed', [
                'to' => $to->address,
                'from' => $from?->address,
                'mailer' => $this->mailerName() ?? config('mail.default'),
                'error' => $e->getMessage(),
            ]);
            throw new TextoSendFailedException('Email send failed: '.$e->getMessage(), 0, $e);
        }

        // SMTP-style transports only confirm acceptance; the RFC 5322 Message-ID is the closest
        // thing to a provider id and is what inbound/status webhooks can later correlate on.
        $providerMessageId = $sent?->getMessageId();

        $metadata['email'] = array_merge($emailOptions, ['subject' => $subject]);

        $result = new SentMessageResult(
            Driver::Email,
            Direction::Sent,
            $to,
            $from,
            $body,
            $mediaUrls,
            $metadata,
            MessageStatus::Sent,
            $providerMessageId,
        );
        Log::info('Texto email sent', [
            'provider_id' => $providerMessageId,
            'to' => $to->address,
            'subject' => $subject,
            'mailer' => $this->mailerName() ?? config('mail.default'),
        ]);

        return $result;
    }

    protected function mailerName(): ?string
    {
        $mailer = $this->config['mailer'] ?? null;

        return is_string($mailer) && $mailer !== '' ? $mailer : null;
    }

    /**
     * Resolve the default sender: texto.email config first, then the host app's global
     * mail.from. Null is acceptable — Laravel applies mail.from itself when the envelope
     * has no from — but resolving here keeps the stored message record accurate.
     */
    protected function defaultFrom(): ?EmailAddress
    {
        $configured = $this->config['from_address'] ?? null;
        if (is_string($configured) && $configured !== '') {
            $name = $this->config['from_name'] ?? null;

            try {
                $parsed = EmailAddress::fromString($configured);

                return new EmailAddress($parsed->address, is_string($name) && $name !== '' ? $name : $parsed->displayName);
            } catch (\Throwable $e) {
                throw new TextoSendFailedException('Email driver from_address is invalid: '.$e->getMessage(), 0, $e);
            }
        }

        $appFrom = config('mail.from.address');
        if (is_string($appFrom) && $appFrom !== '') {
            $appName = config('mail.from.name');

            try {
                return new EmailAddress(EmailAddress::fromString($appFrom)->address, is_string($appName) && $appName !== '' ? $appName : null);
            } catch (\Throwable) {
                return null; // let the mailer surface its own configuration error
            }
        }

        return null;
    }

    protected function assertEmailAddress(AddressInterface $address, string $field): EmailAddress
    {
        if (! $address instanceof EmailAddress) {
            throw new TextoSendFailedException(
                "Email driver requires an email address for '{$field}', got ".$address::class.' ('.$address->value().').'
            );
        }

        return $address;
    }

    /**
     * @return Address[]
     *
     * @throws TextoSendFailedException when an entry is not a valid email address
     */
    protected function toMailAddresses(mixed $raw, string $field): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        $entries = is_array($raw) ? $raw : [$raw];

        $addresses = [];
        foreach ($entries as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new TextoSendFailedException("Email driver '{$field}' entries must be non-empty email address strings.");
            }

            try {
                $parsed = EmailAddress::fromString($entry);
            } catch (\Throwable $e) {
                throw new TextoSendFailedException("Email driver '{$field}' contains an invalid address: ".$e->getMessage(), 0, $e);
            }
            $addresses[] = new Address($parsed->address, $parsed->displayName);
        }

        return $addresses;
    }

    /**
     * Normalize user-provided attachment specs and merge $mediaUrls (URL attachments) so the
     * email driver behaves like the MMS drivers when given media_urls.
     *
     * @param  string[]  $mediaUrls
     * @return array<int, array{path?:string, url?:string, name?:string, mime?:string}>
     */
    protected function normalizeAttachments(mixed $raw, array $mediaUrls): array
    {
        $specs = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_string($entry) && $entry !== '') {
                $specs[] = str_starts_with($entry, 'http://') || str_starts_with($entry, 'https://')
                    ? ['url' => $entry]
                    : ['path' => $entry];

                continue;
            }
            if (is_array($entry) && (isset($entry['path']) || isset($entry['url']))) {
                $spec = array_intersect_key($entry, array_flip(['path', 'url', 'name', 'mime']));
                /** @var array{path?:string, url?:string, name?:string, mime?:string} $spec */
                $specs[] = $spec;

                continue;
            }
            throw new TextoSendFailedException("Email driver 'attachments' entries must be path/url strings or arrays with a 'path' or 'url' key.");
        }

        foreach ($mediaUrls as $url) {
            if ($url !== '') {
                $specs[] = ['url' => $url];
            }
        }

        return $specs;
    }
}
