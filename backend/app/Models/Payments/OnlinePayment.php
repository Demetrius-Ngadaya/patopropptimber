<?php

namespace App\Models\Payments;

use App\Models\Concerns\Auditable;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mobile-money payment requested through the gateway: the customer gets a
 * USSD push and enters their PIN. It is used for a sale only once completed.
 */
class OnlinePayment extends Model
{
    use Auditable;

    public const STATUSES = ['pending', 'completed', 'failed', 'expired', 'voided', 'cancelled'];

    /** Finished: nothing more will happen to it. */
    public const FINAL = ['completed', 'failed', 'expired', 'voided', 'cancelled'];

    public const PROVIDER_NAMES = [
        'mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'mixx' => 'Mixx by Yas', 'tigo' => 'Mixx by Yas',
        'halotel' => 'HaloPesa', 'halopesa' => 'HaloPesa',
    ];

    protected $guarded = ['id'];

    public const STATUS_LABELS = [
        'pending' => 'Waiting for PIN', 'completed' => 'Paid', 'failed' => 'Failed', 'expired' => 'Expired', 'voided' => 'Voided', 'cancelled' => 'Stopped',
    ];

    protected $appends = ['provider_name', 'is_final', 'is_usable', 'status_label', 'purpose_label'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'used_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed()->select('id', 'name', 'username');
    }

    public function scopeForBranch(Builder $q, ?int $branchId): Builder
    {
        return $branchId ? $q->where('branch_id', $branchId) : $q;
    }

    public function getProviderNameAttribute(): ?string
    {
        return $this->provider ? (self::PROVIDER_NAMES[strtolower($this->provider)] ?? ucfirst($this->provider)) : null;
    }

    public function getStatusLabelAttribute(): string
    {
        return __(self::STATUS_LABELS[$this->status] ?? $this->status);
    }

    public function getPurposeLabelAttribute(): string
    {
        return $this->purpose === 'order' ? __('Shop order') : __('Till');
    }

    public function getIsFinalAttribute(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /** Paid and not yet used for a sale. */
    public function getIsUsableAttribute(): bool
    {
        return $this->status === 'completed' && ! $this->sale_id && ! $this->used_at;
    }
}
