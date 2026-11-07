<?php

namespace App\Services\Documents;

use App\Models\Sales\SalesDocument;
use App\Services\Core\SettingsService;
use App\Services\Messaging\Messenger;
use App\Support\AmountInWords;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Everything printed on a quotation / proforma / invoice, laid out the way the
 * admin chose (company details, columns, sections, terms). Used for the PDF
 * and for the customer's online copy.
 */
class SalesDocumentPresenter
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Messenger $messenger,
    ) {}

    public function data(SalesDocument $document): array
    {
        $document->loadMissing(['items', 'branch', 'creator', 'sale']);
        $type = $document->type;
        $columns = array_values((array) $this->settings->get($type.'_columns', []));
        $blocks = array_values((array) $this->settings->get($type.'_blocks', []));
        $has = fn (string $block) => in_array($block, $blocks, true);
        $currency = (string) $this->settings->get('currency');
        $brand = $this->messenger->brand($type);

        return [
            'brand' => $brand,
            'currency' => $currency,
            'columns' => $columns,
            'type' => $type,
            'title' => $document->title(),
            'number' => $document->number,
            'stage' => $document->stage,
            'issue_date' => $document->issue_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'due_label' => $type === SalesDocument::INVOICE ? __('Due date') : __('Valid until'),
            'branch' => $document->branch?->name,
            'served_by' => $has('served_by') ? $document->creator?->name : null,
            'customer' => [
                'name' => $document->customer_name,
                'phone' => $document->customer_phone,
                'email' => $document->customer_email,
                'address' => $has('customer_address') ? $document->customer_address : null,
                'tin' => $has('customer_tin') ? $document->customer_tin : null,
                'vrn' => $has('customer_vrn') ? $document->customer_vrn : null,
            ],
            'items' => $document->items->map(fn ($i) => [
                'description' => $i->description,
                'sku' => in_array('sku', $columns, true) ? $i->product?->sku : null,
                'unit' => $i->unit_name,
                'quantity' => 0 + (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'tax_rate' => (float) $i->tax_rate,
                'tax' => (float) $i->tax,
                'line_total' => (float) $i->line_total,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $document->subtotal,
                'item_discount' => (float) $document->item_discount,
                'discount' => (float) $document->discount,
                'tax' => (float) $document->tax,
                'prices_include_tax' => $document->prices_include_tax,
                'total' => (float) $document->total,
                'paid' => $type === SalesDocument::QUOTATION ? null : $document->amount_paid,
                'balance' => $type === SalesDocument::QUOTATION ? null : $document->balance,
            ],
            'amount_in_words' => $has('amount_in_words') ? AmountInWords::make((float) $document->total, $currency) : null,
            'bank_details' => $has('bank_details') ? (string) $this->settings->get('business_bank_details') : null,
            'terms' => $has('terms') ? $document->terms : null,
            'notes' => $has('notes') ? $document->notes : null,
            'signature' => $has('signature'),
            'footer' => (string) $this->settings->get($type.'_footer'),
            'decline_reason' => $document->decline_reason,
        ];
    }

    public function pdf(SalesDocument $document): string
    {
        return $this->messenger->inMessageLocale(fn () => Pdf::loadView('pdf.sales-document', $this->data($document))->setPaper('a4')->output());
    }

    public function fileName(SalesDocument $document): string
    {
        return $document->number.'.pdf';
    }
}
