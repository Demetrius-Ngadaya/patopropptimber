<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['name', 'date', 'recurring'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'recurring' => 'boolean'];
    }
}
