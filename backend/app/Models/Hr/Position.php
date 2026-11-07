<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    protected $fillable = ['title', 'department_id', 'grade', 'min_salary', 'max_salary', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['min_salary' => 'decimal:2', 'max_salary' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function auditLabel(): string
    {
        return $this->title;
    }
}
