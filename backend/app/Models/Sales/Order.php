<?php

namespace App\Models\Sales;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Payments\OnlinePayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'processing', 'ready', 'out_for_delivery', 'completed', 'cancelled'];

    /** Allowed status changes (completed is reached through the complete action only). */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'ready', 'cancelled'],
        'processing' => ['ready', 'out_for_delivery', 'cancelled'],
        'ready' => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['ready', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_online' => 'decimal:2',
            'paid_online_at' => 'datetime',
            'preferred_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    /** Payments by phone for this order (some may have failed or expired). */
    public function onlinePayments(): HasMany
    {
        return $this->hasMany(OnlinePayment::class)->latest('id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed()->select('id', 'name', 'username');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled'], true);
    }
}
