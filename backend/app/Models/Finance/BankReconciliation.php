<?php

namespace App\Models\Finance;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Matching the books of a bank / mobile money account to the provider statement. */
class BankReconciliation extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['account_id', 'statement_date', 'statement_balance', 'notes'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date:Y-m-d',
            'statement_balance' => 'decimal:2',
            'book_balance' => 'decimal:2',
            'cleared_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'reconciliation_id');
    }

    public function auditLabel(): string
    {
        return $this->account?->name.' @ '.$this->statement_date?->toDateString();
    }
}
