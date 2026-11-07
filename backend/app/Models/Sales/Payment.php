<?php

namespace App\Models\Sales;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Models\Crm\Customer;
use App\Models\Crm\SavingsTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const METHODS = ['cash', 'mobile_money', 'lipa_namba', 'online', 'bank', 'card', 'cheque', 'savings', 'credit_note'];

    /**
     * Methods a cashier can take at the till (credit_note is only used for refunds).
     * "online" is a mobile-money payment requested by USSD push through the
     * payment gateway; it is accepted only once the gateway confirmed it.
     */
    public const TILL_METHODS = ['cash', 'mobile_money', 'lipa_namba', 'online', 'bank', 'card', 'cheque', 'savings'];

    public const PROVIDERS = ['M-Pesa', 'Mixx by Yas', 'Airtel Money', 'HaloPesa', 'T-Pesa', 'AzamPesa', 'CRDB', 'NMB', 'NBC', 'Other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed()->select('id', 'name', 'username');
    }
}
