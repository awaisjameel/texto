<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Http;

/**
 * Generic mailable used by the email driver. It intentionally has no opinion about
 * layout: the caller provides plain text and (optionally) a full HTML body, which are
 * rendered through the package's pass-through views.
 */
class TextoMail extends Mailable
{
    /**
     * @param  Address[]  $ccAddresses
     * @param  Address[]  $bccAddresses
     * @param  Address[]  $replyToAddresses
     * @param  array<int, array{path?:string, url?:string, name?:string, mime?:string}>  $attachmentSpecs
     */
    public function __construct(
        public readonly string $emailSubject,
        public readonly string $textBody,
        public readonly ?string $htmlBody = null,
        public readonly ?Address $fromAddress = null,
        public readonly array $ccAddresses = [],
        public readonly array $bccAddresses = [],
        public readonly array $replyToAddresses = [],
        public readonly array $attachmentSpecs = [],
    ) {}

    public function envelope(): Envelope
    {
        // A null from lets Laravel fall back to the host app's global mail.from config.
        return new Envelope(
            from: $this->fromAddress,
            cc: $this->ccAddresses,
            bcc: $this->bccAddresses,
            replyTo: $this->replyToAddresses,
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        if ($this->htmlBody !== null) {
            return new Content(
                view: 'texto::mail.html',
                text: 'texto::mail.text',
                with: ['html' => $this->htmlBody, 'text' => $this->textBody],
            );
        }

        return new Content(
            text: 'texto::mail.text',
            with: ['text' => $this->textBody],
        );
    }

    /**
     * @return Attachment[]
     */
    public function attachments(): array
    {
        $attachments = [];
        foreach ($this->attachmentSpecs as $spec) {
            $name = isset($spec['name']) && $spec['name'] !== '' ? $spec['name'] : null;
            $mime = isset($spec['mime']) && $spec['mime'] !== '' ? $spec['mime'] : null;

            if (isset($spec['url']) && $spec['url'] !== '') {
                $url = $spec['url'];
                // Remote content is fetched lazily at send time; a failing download must fail
                // the send (Http throw) instead of silently attaching an error page.
                $attachment = Attachment::fromData(
                    static fn (): string => Http::timeout(30)->get($url)->throw()->body(),
                    $name ?? (basename(parse_url($url, PHP_URL_PATH) ?: '') ?: 'attachment'),
                );
            } elseif (isset($spec['path']) && $spec['path'] !== '') {
                $attachment = Attachment::fromPath($spec['path']);
                if ($name !== null) {
                    $attachment = $attachment->as($name);
                }
            } else {
                continue;
            }

            if ($mime !== null) {
                $attachment = $attachment->withMime($mime);
            }
            $attachments[] = $attachment;
        }

        return $attachments;
    }
}
