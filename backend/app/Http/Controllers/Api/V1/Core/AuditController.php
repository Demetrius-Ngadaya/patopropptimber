<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\ActivityLog;
use App\Models\Core\AuditLog;
use App\Models\Core\LoginLog;
use App\Models\Core\SmsLog;
use App\Models\Core\User;
use App\Services\Core\ExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Four separate audit trails:
 *  - activity : every request a user made (who, what, when, from where, result)
 *  - changes  : before / after values of every created, updated, deleted or restored record
 *  - logins   : successful and failed logins, lockouts, logouts and session expiry
 *  - sms      : every SMS sent
 * Stock has its own ledger (stock movements).
 */
class AuditController extends Controller
{
    public function activity(Request $request, ExportService $exporter): JsonResponse|Response
    {
        $query = ActivityLog::query()
            ->with('branch:id,name')
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('method'), fn ($q, $v) => $q->where('method', strtoupper($v)))
            ->when($request->query('status') === 'failed', fn ($q) => $q->where('status_code', '>=', 400))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('path', 'like', "%{$t}%")->orWhere('action', 'like', "%{$t}%")->orWhere('user_name', 'like', "%{$t}%")->orWhere('ip_address', $t)));
        $this->scopeCommon($query, $request);

        return $this->respond($request, $query, $exporter, 'Activity log', [
            'created_at' => ['label' => 'Time', 'type' => 'datetime'],
            'user_name' => 'User',
            'branch.name' => 'Branch',
            'method' => 'Method',
            'action' => 'Action',
            'path' => 'Path',
            'status_code' => 'Result',
            'ip_address' => 'IP address',
            'duration_ms' => ['label' => 'ms', 'type' => 'number'],
        ]);
    }

    public function changes(Request $request, ExportService $exporter): JsonResponse|Response
    {
        $query = AuditLog::query()
            ->with('branch:id,name')
            ->when($request->query('log_type'), fn ($q, $v) => $q->where('log_type', $v))
            ->when($request->query('event'), fn ($q, $v) => $q->where('event', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('auditable_type'), fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($request->query('auditable_id'), fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'like', "%{$t}%")->orWhere('user_name', 'like', "%{$t}%")));
        $this->scopeCommon($query, $request);

        return $this->respond($request, $query, $exporter, 'Data change log', [
            'created_at' => ['label' => 'Time', 'type' => 'datetime'],
            'user_name' => 'User',
            'log_type' => 'Type',
            'event' => 'Event',
            'description' => 'Description',
            'old_values' => 'Before',
            'new_values' => 'After',
            'ip_address' => 'IP address',
        ]);
    }

    public function logins(Request $request, ExportService $exporter): JsonResponse|Response
    {
        $query = LoginLog::query()
            ->with(['user:id,name,username,type', 'branch:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('identifier', 'like', "%{$t}%")->orWhere('ip_address', $t)));
        $this->scopeCommon($query, $request, false);

        return $this->respond($request, $query, $exporter, 'Login history', [
            'created_at' => ['label' => 'Time', 'type' => 'datetime'],
            'identifier' => 'Username typed',
            'user.name' => 'User',
            'status' => 'Result',
            'failure_reason' => 'Reason',
            'ip_address' => 'IP address',
            'user_agent' => 'Device',
            'logged_out_at' => ['label' => 'Logged out', 'type' => 'datetime'],
            'logout_reason' => 'Logout type',
        ]);
    }

    public function sms(Request $request): JsonResponse
    {
        $query = SmsLog::query()
            ->with('sender:id,name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('purpose'), fn ($q, $v) => $q->where('purpose', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('recipient', 'like', "%{$t}%")->orWhere('message', 'like', "%{$t}%")->orWhere('subject', 'like', "%{$t}%")))
            ->latest('id');

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    /** Full history of one record (e.g. a product's price changes). */
    public function record(string $type, int $id): JsonResponse
    {
        return $this->ok(AuditLog::query()
            ->when(! request()->user()?->isDeveloper() && User::developerIds() !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', User::developerIds())))
            ->where('auditable_type', $type)->where('auditable_id', $id)->latest('id')->limit(200)->get());
    }

    private function scopeCommon(Builder $query, Request $request, bool $branch = true): void
    {
        $query->when($request->query('date_from'), fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($request->query('date_to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'));

        // Software Engineers leave no visible trace for anyone else.
        if (! $request->user()?->isDeveloper() && ($hidden = User::developerIds()) !== []) {
            $query->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $hidden));
        }

        if ($branch && ! $this->branch()->canAccessAll()) {
            $query->whereIn('branch_id', $this->branch()->accessibleIds());
        }
        $query->latest('id');
    }

    private function respond(Request $request, Builder $query, ExportService $exporter, string $title, array $columns): JsonResponse|Response
    {
        $format = $request->query('export');
        if (in_array($format, ['xlsx', 'pdf'], true)) {
            $this->requirePermission($request, 'audit.export');

            return $exporter->download($format, $title, $columns, $query->limit(20000)->get(), [], $request->only(['date_from', 'date_to', 'user_id', 'search']));
        }

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }
}
