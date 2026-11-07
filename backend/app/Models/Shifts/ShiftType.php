<?php

namespace App\Models\Shifts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The usual shifts of the business (Morning 07:00-15:00, Night 22:00-06:00 ...). */
class ShiftType extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
