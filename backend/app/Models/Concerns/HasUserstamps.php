<?php

namespace App\Models\Concerns;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by / updated_by / deleted_by automatically.
 */
trait HasUserstamps
{
    public static function bootHasUserstamps(): void
    {
        static::creating(function (Model $model) {
            if (Auth::id() && empty($model->created_by)) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function (Model $model) {
            if (Auth::id()) {
                $model->updated_by = Auth::id();
            }
        });

        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::softDeleted(function (Model $model) {
                if (Auth::id()) {
                    // Quiet save so the change does not create a second audit entry.
                    $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(['deleted_by' => Auth::id()]);
                    $model->setAttribute('deleted_by', Auth::id());
                }
            });

            static::restored(function (Model $model) {
                $model->newQueryWithoutScopes()->whereKey($model->getKey())->update(['deleted_by' => null]);
            });
        }
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed()->select('id', 'name', 'username');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed()->select('id', 'name', 'username');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed()->select('id', 'name', 'username');
    }
}
