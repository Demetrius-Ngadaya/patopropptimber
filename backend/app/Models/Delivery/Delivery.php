<?php

namespace App\Models\Delivery;

use App\Models\Concerns\Auditable;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Sales\Order;
use App\Models\Sales\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Taking an order or a sale to the customer. */
class Delivery extends Model
{
    use Auditable;

    public const STATUSES = ['assigned', 'on_the_way', 'delivered', 'received', 'failed', 'cancelled'];

    public const OPEN = ['assigned', 'on_the_way'];

    protected $guarded = ['id', 'public_token'];

    protected $hidden = ['public_token', 'trail'];

    protected $appends = ['final_fee'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_km' => 'float',
            'fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'courier_latitude' => 'float',
            'courier_longitude' => 'float',
            'trail' => 'array',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'courier_located_at' => 'datetime',
        ];
    }

    public function getFinalFeeAttribute(): float
    {
        return round(max(0, (float) $this->fee - (float) $this->discount), 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function messages(): HasMany
    {
        return $this->hasMany(DeliveryMessage::class)->orderBy('id');
    }
}
