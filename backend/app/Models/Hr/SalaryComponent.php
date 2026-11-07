<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An allowance (earning) or a deduction, fixed or a percentage of basic salary. */
class SalaryComponent extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['name', 'code', 'type', 'calculation', 'amount', 'is_taxable', 'is_pensionable', 'applies_to_all', 'is_active'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'is_pensionable' => 'boolean',
            'applies_to_all' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
