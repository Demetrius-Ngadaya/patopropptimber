<?php

namespace App\Services\Documents;

use App\Models\Core\User;
use App\Models\Sales\SalesDocument;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Services\Messaging\OutgoingMessage;
use App\Support\Channels;
use App\Support\Recipients;

/**
 * Sends a document to the customer:
 *   email     the document as a PDF attachment, with a short covering letter
 *   WhatsApp  the amount, the date and a link to view / download (and accept a quotation)
 *   SMS       a short note saying where the document was sent (or the link, if SMS is all)
 */
class SalesDocumentSender
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly SalesDocumentService $documents,
        private readonly SalesDocumentPresenter $presenter,
        private readonly SettingsService $settings,
    ) {}

    /** @return array{document: SalesDocument, result: array} */
    public function send(SalesDocument $document, array $channels, User $user, ?string $phone = null, ?string $email = null): array
    {
        // Issuing (for a draft invoice) happens first, so the PDF carries the real status.
        $document = $this->documents->markSent($document, $channels, $user);
        $to = Recipients::person($phone ?? $document->customer_phone, $email ?? $document->customer_email);
        $message = $this->messenger->inMessageLocale(fn () => $this->message($document, $channels));
        $result = $this->messenger->send($message, $channels, $to);

        return ['document' => $document->fresh(['items', 'customer', 'branch', 'sale']), 'result' => $result];
    }

    public function link(SalesDocument $document): string
    {
        return rtrim((string) config('mauzo.frontend_url'), '/').'/d/'.$document->public_token;
    }

    private function message(SalesDocument $document, array $channels): OutgoingMessage
    {
        $b = (string) $this->settings->get('business_name');
        $currency = (string) $this->settings->get('currency');
        $title = $document->title();
        $amount = $currency.' '.number_format((float) $document->total);
        $date = $document->due_date?->format('d/m/Y') ?? '-';
        $link = $this->link($document);
        $isInvoice = $document->type === SalesDocument::INVOICE;
        $params = ['business' => $b, 'title' => $title, 'number' => $document->number, 'amount' => $amount, 'date' => $date, 'link' => $link];

        $where = array_values(array_filter([
            in_array(Channels::WHATSAPP, $channels, true) ? __('WhatsApp') : null,
            in_array(Channels::EMAIL, $channels, true) ? __('email') : null,
        ]));
        $sms = $where
            ? __(':business: :title :number (:amount) has been sent to your :where.', $params + ['where' => implode(' '.__('and').' ', $where)])
            : __(':business: :title :number, :amount. View it here: :link', $params);

        $lines = ["*{$b}*", "{$title} *{$document->number}*", __('Amount: :amount', $params), ($isInvoice ? __('Due date') : __('Valid until')).": {$date}", '', __('View and download: :link', $params)];
        if ($document->type === SalesDocument::QUOTATION) {
            $lines[] = __('You can accept or decline the quotation on the same page.');
        }
        $lines[] = '';
        $lines[] = '_'.config('mauzo.brand.powered_by').'_';

        $doc = [
            'title' => "{$title} {$document->number}",
            'intro' => [
                __('Dear :name,', ['name' => $document->customer_name]),
                __('Please find attached our :title :number.', ['title' => mb_strtolower($title), 'number' => $document->number]),
            ],
            'meta' => array_filter([
                __('Amount') => $amount,
                ($isInvoice ? __('Due date') : __('Valid until')) => $date,
                __('Balance') => $isInvoice && $document->balance > 0 ? $currency.' '.number_format($document->balance) : null,
            ]),
            'sections' => [[
                'heading' => __('Items'),
                'columns' => [__('Description'), __('Quantity'), __('Total')],
                'numeric' => [1, 2],
                'rows' => $document->items->map(fn ($i) => [$i->description.($i->unit_name ? " ({$i->unit_name})" : ''), 0 + (float) $i->quantity, number_format((float) $i->line_total)])->all(),
                'total' => [__('Total')." ({$currency})", '', number_format((float) $document->total)],
            ]],
            'closing' => array_values(array_filter([
                __('You can also view it online: :link', $params),
                $document->type === SalesDocument::QUOTATION ? __('You can accept or decline the quotation on the same page.') : null,
                $document->terms,
            ])),
        ];

        return new OutgoingMessage(
            $document->type, $sms, implode("\n", $lines), __(':title :number from :business', $params), $doc, $this->presenter->fileName($document),
            $document->getMorphClass(), $document->id,
            pdfView: 'pdf.sales-document', pdfData: $this->presenter->data($document),
        );
    }
}
