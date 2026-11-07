<?php

namespace App\Models\Agency;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Commission a provider paid for a month's agency work (usually received around month end). */
class AgencyCommission extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_on' => 'date:Y-m-d'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AgencyAccount::class, 'agency_account_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }
}
