<?php

namespace App\Models\Payments;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'verified' => 'boolean', 'processed_at' => 'datetime'];
    }
}
