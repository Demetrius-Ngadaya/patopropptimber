<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A package a business can be on (Msingi, Biashara, Pro…), managed by the
 * Software Engineer. Lives in the central database.
 *
 * @property array<int, string> $features ["*"] for everything, else feature keys
 * @property array<string, int|null> $limits
 */
class Plan extends Model
{
    protected $table = 'platform_plans';

    protected $guarded = ['id'];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_recommended' => 'boolean',
        'trial_days' => 'integer',
        'sort_order' => 'integer',
    ];

    public function getConnectionName(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /** For the pricing and sign-up pages. */
    public function toPublicArray(): array
    {
        $access = \App\Platform\Support\PlanAccess::fromPlan($this)->toArray();

        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'price_monthly' => (float) $this->price_monthly,
            'price_yearly' => (float) $this->price_yearly,
            'currency' => $this->currency,
            'trial_days' => $this->trialDays(),
            'recommended' => $this->is_recommended,
            'features' => $access['features'],
            'limits' => $access['limits'],
        ];
    }

    public function trialDays(): int
    {
        return $this->trial_days ?? (int) config('patopro.platform.subscription.trial_days');
    }
}
