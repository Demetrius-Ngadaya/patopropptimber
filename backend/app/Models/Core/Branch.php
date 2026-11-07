<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Inventory\ProductStock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use Auditable, HasFactory, HasUserstamps, SoftDeletes;

    protected $fillable = ['name', 'code', 'location', 'address', 'latitude', 'longitude', 'phone', 'email', 'is_main', 'is_active', 'accepts_online_orders'];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
            'accepts_online_orders' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }
}
