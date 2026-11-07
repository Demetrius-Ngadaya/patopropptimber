<?php

namespace App\Models\Agency;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyDayBalance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opening_float' => 'decimal:2', 'closing_float' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AgencyAccount::class, 'agency_account_id')->withTrashed();
    }
}
