<?php

namespace App\Services\Messaging;

use Illuminate\Database\Eloquent\Model;

/**
 * One message, written three ways:
 *  - sms:      a very short summary (one SMS where possible)
 *  - text:     a fuller WhatsApp message
 *  - document: the detailed version, used for the email body AND the PDF attached to it
 *
 * The document is plain data so the message can be queued and rendered later:
 * [
 *   'title' => string, 'subtitle' => ?string,
 *   'intro' => string[],                      paragraphs before the tables
 *   'meta' => [label => value],               key facts shown as a list
 *   'sections' => [[
 *       'heading' => string, 'columns' => string[], 'rows' => array[],
 *       'numeric' => int[] (column indexes aligned right), 'total' => ?array (last row, bold),
 *       'empty' => ?string,
 *   ]],
 *   'closing' => string[],                    paragraphs after the tables
 * ]
 */
final class OutgoingMessage
{
    public function __construct(
        public readonly string $purpose,
        public readonly string $sms,
        public readonly string $text,
        public readonly string $subject,
        public readonly array $document,
        public readonly string $attachmentName = 'details.pdf',
        public readonly ?string $relatedType = null,
        public readonly ?int $relatedId = null,
        /** Optional own PDF template (e.g. the period report); otherwise pdf.document renders $document. */
        public readonly ?string $pdfView = null,
        public readonly array $pdfData = [],
    ) {}

    public function withRelated(?Model $model): self
    {
        return new self($this->purpose, $this->sms, $this->text, $this->subject, $this->document, $this->attachmentName,
            $model?->getMorphClass(), $model?->getKey(), $this->pdfView, $this->pdfData);
    }
}
