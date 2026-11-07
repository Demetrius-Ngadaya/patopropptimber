<?php

namespace App\Models\Sales;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Services\Core\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A quotation, proforma invoice or invoice.
 *
 * Stored `status` is the lifecycle step people take; `stage` is what the
 * document is now, which for an issued invoice comes from its sale (so a
 * payment taken anywhere, e.g. at the till, shows here immediately):
 *
 *   quotation  draft → sent → accepted | declined | expired* → converted        (+ cancelled)
 *   proforma   draft → awaiting_payment (expired*) → paid → closed               (+ cancelled)
 *   invoice    draft → issued: owing* | overdue* | paid* → closed                (+ cancelled)
 *                                                       * worked out, not stored
 */
class SalesDocument extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const QUOTATION = 'quotation';

    public const PROFORMA = 'proforma';

    public const INVOICE = 'invoice';

    public const TYPES = [self::QUOTATION, self::PROFORMA, self::INVOICE];

    /** Stages shown in lists and filters, per type. */
    public const STAGES = [
        self::QUOTATION => ['draft', 'sent', 'accepted', 'declined', 'expired', 'converted', 'cancelled'],
        self::PROFORMA => ['draft', 'awaiting_payment', 'paid', 'closed', 'cancelled'],
        self::INVOICE => ['draft', 'owing', 'overdue', 'paid', 'closed', 'cancelled'],
    ];

    protected $guarded = ['id', 'public_token'];

    protected $appends = ['stage', 'amount_paid', 'balance', 'expired'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'item_discount' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDocumentItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesDocumentEvent::class)->latest('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function convertedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_from_id')->withTrashed();
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_id')->withTrashed();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Past its due / valid-until date (only matters while still open). */
    public function getExpiredAttribute(): bool
    {
        return $this->due_date !== null && $this->due_date->lt(today());
    }

    public function getStageAttribute(): string
    {
        if ($this->type === self::INVOICE && $this->status === 'issued') {
            $sale = $this->sale;
            if (! $sale || $sale->status === Sale::STATUS_CANCELLED) {
                return 'cancelled';
            }
            if ((float) $sale->balance <= 0.004) {
                return 'paid';
            }

            return $this->expired ? 'overdue' : 'owing';
        }
        if ($this->type === self::QUOTATION && $this->status === 'sent' && $this->expired) {
            return 'expired';
        }

        return (string) $this->status;
    }

    public function getAmountPaidAttribute(): float
    {
        $sale = $this->sale_id ? $this->sale : null;

        return $sale && $sale->status !== Sale::STATUS_CANCELLED ? round((float) $sale->amount_paid, 2) : 0.0;
    }

    public function getBalanceAttribute(): float
    {
        if ($this->type === self::QUOTATION || in_array($this->status, ['draft', 'cancelled'], true)) {
            return 0.0;
        }
        if ($this->sale_id && $this->sale) {
            return $this->sale->status === Sale::STATUS_CANCELLED ? 0.0 : round((float) $this->sale->balance, 2);
        }

        return round((float) $this->total, 2);
    }

    /** Filter by the stage people see (some stages are worked out from the sale and the dates). */
    public function scopeStage(Builder $query, string $stage): Builder
    {
        $today = today()->toDateString();
        $owes = fn ($q) => $q->where('status', Sale::STATUS_COMPLETED)->where('balance', '>', 0);

        return match ($stage) {
            'owing' => $query->where('status', 'issued')->whereHas('sale', $owes)->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today)),
            'overdue' => $query->where('status', 'issued')->whereHas('sale', $owes)->where('due_date', '<', $today),
            // Proformas are stored as paid; issued invoices are paid when their sale is.
            'paid' => $query->where(fn ($w) => $w->where('status', 'paid')
                ->orWhere(fn ($q) => $q->where('status', 'issued')->whereHas('sale', fn ($s) => $s->where('status', Sale::STATUS_COMPLETED)->where('balance', '<=', 0)))),
            'expired' => $query->where('status', 'sent')->where('due_date', '<', $today),
            'sent' => $query->where('status', 'sent')->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today)),
            'cancelled' => $query->where(fn ($w) => $w->where('status', 'cancelled')->orWhere(fn ($q) => $q->where('status', 'issued')->whereHas('sale', fn ($s) => $s->where('status', Sale::STATUS_CANCELLED)))),
            default => $query->where('status', $stage),
        };
    }

    /** The title printed on the document. */
    public function title(): string
    {
        return (string) app(SettingsService::class)->get($this->type.'_title');
    }
}
