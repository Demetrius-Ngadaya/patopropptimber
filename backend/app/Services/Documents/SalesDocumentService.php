<?php

namespace App\Services\Documents;

use App\Exceptions\BusinessException;
use App\Models\Catalog\Product;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Sales\Payment;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentEvent;
use App\Services\Core\AuditLogger;
use App\Services\Core\NumberGenerator;
use App\Services\Core\SettingsService;
use App\Services\Sales\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The life of a quotation, proforma invoice or invoice: drafting, sending,
 * customer answers, conversion, issuing (which creates the sale), payments,
 * closing and cancelling. Prices come from the same engine as the till.
 */
class SalesDocumentService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly NumberGenerator $numbers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a draft, or update an editable document.
     *
     * @param  array{customer_id?: ?int, customer?: array, items: array, discount?: ?float, issue_date?: ?string, due_date?: ?string, notes?: ?string, terms?: ?string, price_level?: ?string}  $data
     */
    public function save(string $type, array $data, User $user, int $branchId, ?SalesDocument $document = null): SalesDocument
    {
        if ($document && ! $this->editable($document)) {
            throw new BusinessException(__('This :type can no longer be changed.', ['type' => __($document->type)]));
        }

        return DB::transaction(function () use ($type, $data, $branchId, $document) {
            $customer = ! empty($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null;
            $given = (array) ($data['customer'] ?? []);
            if (! $customer && blank($given['name'] ?? null)) {
                throw new BusinessException(__('Choose a customer or type the customer\'s name.'), 'CUSTOMER_REQUIRED');
            }

            $priced = $this->price($data, $customer);
            $issue = Carbon::parse($data['issue_date'] ?? today());
            $due = ! empty($data['due_date'])
                ? Carbon::parse($data['due_date'])
                : $issue->copy()->addDays((int) $this->settings->get($type === SalesDocument::INVOICE ? 'invoice_due_days' : $type.'_valid_days'));

            $fields = [
                'branch_id' => $document?->branch_id ?? $branchId,
                'customer_id' => $customer?->id,
                'customer_name' => $given['name'] ?? $customer?->name,
                'customer_phone' => $given['phone'] ?? $customer?->phone,
                'customer_email' => $given['email'] ?? $customer?->email,
                'customer_address' => $given['address'] ?? $customer?->address,
                'customer_tin' => $given['tin'] ?? $customer?->tin,
                'customer_vrn' => $given['vrn'] ?? $customer?->vrn,
                'price_level' => $priced['price_level'],
                'issue_date' => $issue->toDateString(),
                'due_date' => $due->toDateString(),
                'subtotal' => $priced['subtotal'],
                'item_discount' => $priced['item_discount'],
                'discount' => $priced['discount'],
                'tax' => $priced['tax'],
                'total' => $priced['total'],
                'prices_include_tax' => $this->settings->bool('prices_include_tax'),
                'notes' => $data['notes'] ?? null,
                'terms' => array_key_exists('terms', $data) ? $data['terms'] : (string) $this->settings->get($type.'_terms'),
            ];

            if ($document) {
                $document->fill($fields)->save();
                $document->items()->delete();
                $this->event($document, 'updated');
            } else {
                $document = new SalesDocument($fields + ['type' => $type, 'status' => 'draft']);
                $document->number = $this->numbers->next($type);
                $document->public_token = Str::random(40);
                $document->save();
                $this->event($document, 'created');
            }

            foreach ($priced['lines'] as $i => $line) {
                $document->items()->create($line + ['sort_order' => $i]);
            }

            return $document->fresh(['items', 'customer', 'branch']);
        });
    }

    /** Totals for the editor, without saving anything. */
    public function preview(array $data): array
    {
        $customer = ! empty($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null;

        return $this->price($data, $customer);
    }

    public function editable(SalesDocument $document): bool
    {
        return match ($document->type) {
            SalesDocument::QUOTATION => in_array($document->status, ['draft', 'sent'], true),
            SalesDocument::PROFORMA => in_array($document->status, ['draft', 'awaiting_payment'], true),
            default => $document->status === 'draft',
        };
    }

    /**
     * Issue a draft invoice: it becomes a credit sale to the customer
     * (stock goes out, the debt is recorded, the books are posted).
     */
    public function issue(SalesDocument $document, User $user): SalesDocument
    {
        if ($document->type !== SalesDocument::INVOICE || $document->status !== 'draft') {
            throw new BusinessException(__('Only a draft invoice can be issued.'));
        }
        if (! $document->customer_id) {
            throw new BusinessException(__('Choose a registered customer before issuing: the invoice becomes their debt.'), 'CUSTOMER_REQUIRED');
        }

        return DB::transaction(function () use ($document, $user) {
            $sale = $this->sales->create($this->saleData($document, [], $document->due_date?->toDateString()), $user, $document->branch_id, [
                'enforce_permissions' => false,
                'allow_credit_without_permission' => true,
                'source' => $document,
            ]);
            $document->forceFill(['status' => 'issued', 'issued_at' => now(), 'sale_id' => $sale->id])->save();
            $this->event($document, 'issued', __('Sale :number created', ['number' => $sale->number]));
            $this->audit->event('invoice.issued', __('Invoice :number issued for :amount', ['number' => $document->number, 'amount' => number_format((float) $document->total, 2)]), $document);

            return $document->fresh(['items', 'customer', 'branch', 'sale']);
        });
    }

    /**
     * Money received against an invoice (any part of the balance) or a
     * proforma (in full: the goods are then sold and released).
     *
     * @param  array{amount: float, method: string, provider?: ?string, reference?: ?string}  $data
     */
    public function receivePayment(SalesDocument $document, array $data, User $user, int $branchId): SalesDocument
    {
        if ($document->type === SalesDocument::INVOICE) {
            if ($document->status !== 'issued' || in_array($document->stage, ['paid', 'cancelled'], true)) {
                throw new BusinessException(__('This invoice has nothing left to pay.'));
            }
            $this->sales->payCreditSale($document->sale, ['mode' => 'partial'] + $data, $user, $branchId);
            $document->refresh();
            $this->event($document, 'payment', number_format((float) $data['amount'], 2).' · '.$data['method'], ['amount' => (float) $data['amount'], 'method' => $data['method']]);
            if ($document->stage === 'paid') {
                $document->forceFill(['paid_at' => now()])->save();
                $this->event($document, 'paid');
            }

            return $document->fresh(['items', 'customer', 'branch', 'sale']);
        }

        if ($document->type === SalesDocument::PROFORMA) {
            if (! in_array($document->status, ['draft', 'awaiting_payment'], true)) {
                throw new BusinessException(__('This proforma is not waiting for payment.'));
            }
            if ((float) $data['amount'] + 0.004 < (float) $document->total) {
                throw new BusinessException(__('A proforma is paid in full (:total). For a part payment, convert it to an invoice first.', ['total' => number_format((float) $document->total, 2)]), 'PART_PAYMENT');
            }

            return DB::transaction(function () use ($document, $data, $user, $branchId) {
                $sale = $this->sales->create($this->saleData($document, [$data]), $user, $branchId, ['enforce_permissions' => false, 'source' => $document]);
                $document->forceFill(['status' => 'paid', 'paid_at' => now(), 'sale_id' => $sale->id])->save();
                $this->event($document, 'paid', __('Sale :number created', ['number' => $sale->number]), ['amount' => (float) $data['amount'], 'method' => $data['method']]);

                return $document->fresh(['items', 'customer', 'branch', 'sale']);
            });
        }

        throw new BusinessException(__('Quotations do not take payments. Convert it to a proforma or an invoice.'));
    }

    /** Copy into a new draft of another type (quotation → proforma / invoice, proforma → invoice). */
    public function convert(SalesDocument $document, string $to, User $user): SalesDocument
    {
        $allowed = [SalesDocument::QUOTATION => [SalesDocument::PROFORMA, SalesDocument::INVOICE], SalesDocument::PROFORMA => [SalesDocument::INVOICE]];
        if (! in_array($to, $allowed[$document->type] ?? [], true)) {
            throw new BusinessException(__('This document cannot be turned into a :type.', ['type' => __($to)]));
        }
        $open = $document->type === SalesDocument::QUOTATION ? ['draft', 'sent', 'accepted'] : ['draft', 'awaiting_payment'];
        if (! in_array($document->status, $open, true) || $document->converted_to_id) {
            throw new BusinessException(__('This :type has already been closed or converted.', ['type' => __($document->type)]));
        }

        return DB::transaction(function () use ($document, $to, $user) {
            $document->loadMissing('items');
            $new = $this->save($to, [
                'customer_id' => $document->customer_id,
                'customer' => ['name' => $document->customer_name, 'phone' => $document->customer_phone, 'email' => $document->customer_email, 'address' => $document->customer_address, 'tin' => $document->customer_tin, 'vrn' => $document->customer_vrn],
                'price_level' => $document->price_level,
                'items' => $document->items->map(fn ($i) => [
                    'product_id' => $i->product_id, 'product_unit_id' => $i->product_unit_id, 'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price, 'discount' => (float) $i->discount, 'description' => $i->description,
                ])->all(),
                'discount' => (float) $document->discount,
                'notes' => $document->notes,
            ], $user, $document->branch_id);
            $new->forceFill(['converted_from_id' => $document->id])->save();

            $document->forceFill([
                'converted_to_id' => $new->id,
                'status' => $document->type === SalesDocument::QUOTATION ? 'converted' : 'closed',
                'closed_at' => $document->type === SalesDocument::PROFORMA ? now() : $document->closed_at,
            ])->save();
            $this->event($document, 'converted', $new->number, ['to' => $new->id]);
            $this->event($new, 'created', __('From :number', ['number' => $document->number]));

            return $new;
        });
    }

    /** The document has gone out to the customer. */
    public function markSent(SalesDocument $document, array $channels, User $user): SalesDocument
    {
        if ($document->status === 'draft') {
            if ($document->type === SalesDocument::INVOICE) {
                $document = $this->issue($document, $user);
            } else {
                $document->status = $document->type === SalesDocument::QUOTATION ? 'sent' : 'awaiting_payment';
            }
        }
        $document->sent_at = now();
        $document->save();
        $this->event($document, 'sent', implode(', ', $channels), ['channels' => $channels]);

        return $document;
    }

    /** The customer (on the public link) or staff record the answer to a quotation. */
    public function respond(SalesDocument $document, bool $accept, ?string $reason = null): SalesDocument
    {
        if ($document->type !== SalesDocument::QUOTATION || ! in_array($document->status, ['draft', 'sent'], true)) {
            throw new BusinessException(__('This quotation can no longer be accepted or declined.'));
        }
        if ($accept && $document->expired) {
            throw new BusinessException(__('This quotation has expired. Ask for a new one.'), 'EXPIRED');
        }
        $document->forceFill($accept
            ? ['status' => 'accepted', 'accepted_at' => now()]
            : ['status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason])->save();
        $this->event($document, $accept ? 'accepted' : 'declined', $reason);

        return $document;
    }

    /** First time the customer opens the public link. */
    public function markViewed(SalesDocument $document): void
    {
        if (! $document->viewed_at && $document->status !== 'draft') {
            $document->forceFill(['viewed_at' => now()])->saveQuietly();
            $this->event($document, 'viewed');
        }
    }

    /** Archive a finished document (a paid invoice or proforma, an answered quotation). */
    public function close(SalesDocument $document): SalesDocument
    {
        $closable = match ($document->type) {
            SalesDocument::INVOICE => $document->stage === 'paid',
            SalesDocument::PROFORMA => $document->status === 'paid',
            default => in_array($document->stage, ['accepted', 'declined', 'expired'], true),
        };
        if (! $closable) {
            throw new BusinessException(__('Only a paid or finished :type can be closed.', ['type' => __($document->type)]));
        }
        $document->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->event($document, 'closed');

        return $document;
    }

    /** Cancel. An issued invoice cancels its sale: stock comes back and payments are refunded. */
    public function cancel(SalesDocument $document, string $reason, User $user): SalesDocument
    {
        if (in_array($document->status, ['cancelled', 'closed', 'converted', 'paid'], true)) {
            throw new BusinessException(__('This :type cannot be cancelled.', ['type' => __($document->type)]));
        }

        return DB::transaction(function () use ($document, $reason, $user) {
            if ($document->status === 'issued' && $document->sale && $document->sale->status !== 'cancelled') {
                $this->sales->cancel($document->sale, $user, __('Invoice :number cancelled: :reason', ['number' => $document->number, 'reason' => $reason]));
            }
            $document->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->event($document, 'cancelled', $reason);
            $this->audit->event($document->type.'.cancelled', __(':number cancelled: :reason', ['number' => $document->number, 'reason' => $reason]), $document);

            return $document->fresh(['items', 'customer', 'branch', 'sale']);
        });
    }

    public function event(SalesDocument $document, string $event, ?string $note = null, array $data = []): void
    {
        SalesDocumentEvent::create([
            'sales_document_id' => $document->id,
            'event' => $event,
            'note' => $note ? mb_substr($note, 0, 500) : null,
            'data' => $data ?: null,
            'user_id' => Auth::id(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** Prices and totals from the till's pricing engine, plus the printed wording of each line. */
    private function price(array $data, ?Customer $customer): array
    {
        $items = array_values(array_filter((array) ($data['items'] ?? []), fn ($l) => ! empty($l['product_id']) && (float) ($l['quantity'] ?? 0) > 0));
        if ($items === []) {
            throw new BusinessException(__('Add at least one product.'));
        }
        $quote = $this->sales->quote(['customer_id' => $customer?->id, 'price_level' => $data['price_level'] ?? null, 'items' => $items, 'discount' => $data['discount'] ?? 0]);
        $products = Product::query()->whereIn('id', collect($items)->pluck('product_id'))->get()->keyBy('id');

        $lines = [];
        $itemDiscount = 0.0;
        foreach ($quote['lines'] as $q) {
            $product = $products[$q['product_id']] ?? null;
            if (! $product) {
                continue;
            }
            $input = $items[$q['index']];
            $itemDiscount += (float) $q['discount'];
            $lines[] = [
                'product_id' => $product->id,
                'product_unit_id' => $q['product_unit_id'],
                'description' => trim((string) ($input['description'] ?? '')) ?: $product->name,
                'unit_name' => $q['unit_name'],
                'unit_factor' => $q['factor'],
                'quantity' => round((float) $input['quantity'], 3),
                'unit_price' => $q['unit_price'],
                'discount' => $q['discount'],
                'tax_rate' => (float) $product->tax_rate,
                'tax' => $q['tax'],
                'line_total' => $q['line_total'],
            ];
        }
        if ($lines === []) {
            throw new BusinessException(__('Add at least one product.'));
        }
        $docDiscount = round(max(0, (float) ($data['discount'] ?? 0)), 2);
        if ($quote['subtotal'] - $itemDiscount - $docDiscount < 0) {
            throw new BusinessException(__('The discount cannot be larger than the amount.'));
        }

        return [
            'price_level' => $quote['price_level'],
            'lines' => $lines,
            'subtotal' => $quote['subtotal'],
            'item_discount' => round($itemDiscount, 2),
            'discount' => $docDiscount,
            'tax' => $quote['tax'],
            'total' => $quote['total'],
        ];
    }

    /** The sale that stands behind an issued invoice or a paid proforma. */
    private function saleData(SalesDocument $document, array $payments, ?string $dueDate = null): array
    {
        $document->loadMissing('items');

        return [
            'customer_id' => $document->customer_id,
            'customer_name' => $document->customer_id ? null : $document->customer_name,
            'customer_phone' => $document->customer_id ? null : $document->customer_phone,
            'price_level' => $document->price_level,
            'items' => $document->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'product_unit_id' => $i->product_unit_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
            ])->all(),
            'discount' => (float) $document->discount,
            'payments' => array_map(fn ($p) => array_intersect_key($p, array_flip(['method', 'amount', 'provider', 'reference', 'payer_phone'])), $payments),
            'due_date' => $dueDate,
            // An invoice dated earlier is recorded on its own date (so ageing and reports match the paper).
            'sold_at' => $dueDate !== null && $document->issue_date?->lt(today()) ? $document->issue_date->toDateString().' 12:00:00' : null,
            'notes' => __(':type :number', ['type' => $document->title(), 'number' => $document->number]),
            'channel' => $document->type,
            'receipt_channels' => [],
        ];
    }

    /** All payments recorded against a document's sale. */
    public function payments(SalesDocument $document): array
    {
        if (! $document->sale_id) {
            return [];
        }

        return Payment::query()->where('sale_id', $document->sale_id)->orderBy('paid_at')
            ->get(['id', 'number', 'type', 'amount', 'method', 'provider', 'reference', 'paid_at'])->all();
    }
}
