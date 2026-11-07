<?php

namespace App\Services\Core;

use App\Models\Core\AuditLog;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Central writer for the audit trail. Never throws: a logging failure must not
 * break the business operation, so failures are written to the Laravel log.
 */
class AuditLogger
{
    public function __construct(private readonly BranchContext $branch) {}

    public function modelEvent(string $event, Model $model, array $old, array $new): void
    {
        $label = method_exists($model, 'auditLabel') ? $model->auditLabel() : '#'.$model->getKey();
        $name = Str::headline(class_basename($model));
        $description = ucfirst($event)." {$name} {$label}";
        if (property_exists($model, 'auditReason') && $model->auditReason) {
            $description .= ' — reason: '.$model->auditReason;
        }

        $this->write([
            'log_type' => 'data',
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'description' => Str::limit($description, 495),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'branch_id' => $model->getAttribute('branch_id') ?? $this->branch->id(),
        ]);
    }

    /**
     * Business / auth / security events, e.g. sale.completed, login.failed.
     */
    public function event(string $event, string $description, ?Model $subject = null, array $data = [], string $type = 'business', ?int $userId = null, ?string $userName = null): void
    {
        $this->write([
            'log_type' => $type,
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => Str::limit($description, 495),
            'new_values' => $data ?: null,
            'branch_id' => $subject?->getAttribute('branch_id') ?? $this->branch->id(),
            'user_id' => $userId,
            'user_name' => $userName,
        ]);
    }

    private function write(array $attributes): void
    {
        try {
            $user = Auth::user();
            $request = app()->runningInConsole() ? null : request();

            AuditLog::create(array_merge([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 495, '') : 'console',
                'url' => $request ? Str::limit($request->fullUrl(), 495, '') : null,
                'method' => $request?->method(),
            ], array_filter($attributes, fn ($v) => $v !== null)));
        } catch (Throwable $e) {
            Log::error('Audit log write failed: '.$e->getMessage(), ['attributes' => $attributes]);
        }
    }
}
