<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The detailed email version of a message, with the same content as a PDF attachment. */
class NotificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $mailSubject,
        public readonly array $document,
        public readonly array $brand,
        public readonly ?string $pdf,
        public readonly string $pdfName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.notification',
            text: 'emails.notification-text',
            with: ['doc' => $this->document, 'brand' => $this->brand],
        );
    }

    public function attachments(): array
    {
        return $this->pdf === null ? [] : [Attachment::fromData(fn () => $this->pdf, $this->pdfName)->withMime('application/pdf')];
    }
}
