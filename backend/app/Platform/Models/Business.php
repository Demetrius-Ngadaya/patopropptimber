<?php

namespace App\Platform\Models;

use App\Platform\Support\SubscriptionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One business on the platform, with its own database. Lives in the central database.
 *
 * @property string $slug
 * @property string $name
 * @property string $db_name
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $paid_until
 * @property Carbon|null $suspended_at
 * @property Carbon|null $archived_at
 */
class Business extends Model
{
    protected $table = 'platform_businesses';

    protected $guarded = ['id'];

    protected $hidden = ['db_password', 'db_username', 'db_host'];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'paid_until' => 'datetime',
        'suspended_at' => 'datetime',
        'archived_at' => 'datetime',
        'db_password' => 'encrypted',
        'meta' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return config('patopro.platform.connection');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** End of the period the business has (paid or trial), whichever is later. */
    public function servedUntil(): ?Carbon
    {
        $dates = array_filter([$this->paid_until, $this->trial_ends_at]);

        return $dates ? max($dates) : null;
    }

    public function state(?Carbon $now = null): SubscriptionState
    {
        return SubscriptionState::for($this, $now ?? now());
    }

    public function url(string $path = ''): string
    {
        return config('patopro.platform.url').'/'.$this->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}
