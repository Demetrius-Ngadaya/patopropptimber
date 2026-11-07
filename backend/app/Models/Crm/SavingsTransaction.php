<?php

namespace App\Models\Crm;

use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Sales\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable ledger line of a savings account. Mistakes are corrected with a
 * reversal transaction, never by editing or deleting.
 */
class SavingsTransaction extends Model
{
    public const DEPOSIT = 'deposit';

    public const PURCHASE = 'purchase';

    public const WITHDRAWAL = 'withdrawal';

    public const REFUND = 'refund';

    public const DEBT_PAYMENT = 'debt_payment';

    public const REVERSAL = 'reversal';

    /** Types that add money to the account. */
    public const CREDITS = [self::DEPOSIT, self::REFUND];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'purchase_amount' => 'decimal:2',
            'debt_amount' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'savings_account_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed()->select('id', 'name', 'username');
    }

    public function signedAmount(): float
    {
        return $this->balance_after - $this->balance_before;
    }
}
