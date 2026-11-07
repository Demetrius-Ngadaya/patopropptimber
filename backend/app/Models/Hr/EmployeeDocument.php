<?php

namespace App\Models\Hr;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Stored on the private disk; only downloadable through an authorised endpoint. */
class EmployeeDocument extends Model
{
    use Auditable, HasUserstamps, SoftDeletes;

    public const TYPES = ['contract', 'id', 'certificate', 'cv', 'letter', 'other'];

    protected $fillable = ['employee_id', 'title', 'type', 'file_path', 'original_name', 'mime', 'size', 'expires_on'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['expires_on' => 'date:Y-m-d', 'size' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
