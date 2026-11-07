<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['name', 'code', 'days_per_year', 'is_paid', 'max_carry_forward', 'requires_attachment', 'gender', 'is_active'];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:1',
            'max_carry_forward' => 'decimal:1',
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
