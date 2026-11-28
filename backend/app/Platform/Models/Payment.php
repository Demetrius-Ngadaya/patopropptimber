<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment for a package (central database). */
class Payment extends Model
{
    protected $table = 'platform_payments';

    protected $guarded = ['id'];

    protected $hidden = ['gateway_response'];

    protected $casts = [
        'amount' => 'decimal:2',
        'months' => 'integer',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
        'period_from' => 'datetime',
        'period_to' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public static function nextNumber(): string
    {
        $year = now()->format('Y');
        $last = (string) static::query()->where('number', 'like', "PP-$year-%")->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, -6)) + 1 : 1;

        return sprintf('PP-%s-%06d', $year, $n);
    }

    public function toPublicArray(): array
    {
        return [
            'number' => $this->number,
            'plan' => $this->plan?->name,
            'billing_cycle' => $this->billing_cycle,
            'months' => $this->months,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'method' => $this->method,
            'phone' => $this->phone,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'period_from' => $this->period_from?->toIso8601String(),
            'period_to' => $this->period_to?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
