<?php

namespace App\Models\Concerns;

use App\Services\Core\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes a data-change audit entry (with before / after values) whenever the
 * model is created, updated, soft deleted or restored.
 *
 * Models may define:
 *  - protected array $auditExclude = ['field'];   extra fields never logged
 *  - public ?string $auditReason                  set before save() to attach a reason
 */
trait Auditable
{
    public ?string $auditReason = null;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditLogger::class)->modelEvent('created', $model, [], $model->auditableValues($model->attributesToArray()));
        });

        static::updated(function (Model $model) {
            $changedKeys = array_diff(array_keys($model->getChanges()), ['updated_at', 'updated_by']);
            if ($changedKeys === []) {
                return;
            }
            // attributesToArray() applies casts, so both sides are formatted the same way (e.g. "1500.00").
            $new = $model->auditableValues(Arr::only($model->attributesToArray(), $changedKeys));
            $old = $model->auditableValues(Arr::only($model->getOriginal(), $changedKeys));
            if ($new === [] && $old === []) {
                return;
            }
            app(AuditLogger::class)->modelEvent('updated', $model, $old, $new);
        });

        static::deleted(function (Model $model) {
            app(AuditLogger::class)->modelEvent('deleted', $model, $model->auditableValues($model->attributesToArray()), []);
        });

        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::restored(function (Model $model) {
                app(AuditLogger::class)->modelEvent('restored', $model, [], ['deleted_at' => null]);
            });
        }
    }

    /** Strip secrets and noise from a set of attributes. */
    public function auditableValues(array $values): array
    {
        $exclude = array_merge(
            ['password', 'remember_token', 'created_at', 'deleted_by'],
            $this->getHidden(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        $clean = array_diff_key($values, array_flip($exclude));

        foreach ($clean as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $clean[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_string($value) && strlen($value) > 2000) {
                $clean[$key] = substr($value, 0, 2000).'…';
            }
        }

        return $clean;
    }

    /** Human readable name used in audit descriptions. */
    public function auditLabel(): string
    {
        foreach (['number', 'name', 'title', 'code', 'username', 'key'] as $field) {
            if (! empty($this->getAttribute($field))) {
                return (string) $this->getAttribute($field);
            }
        }

        return '#'.$this->getKey();
    }
}
