<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Models\Core\AppRelease;
use App\Models\Mobile\MobileInstall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Who has the app: phones report when the app is opened and when a sign-in fails
 * (no login needed for that), and admins see the "Mobile app users" report.
 */
class MobileInstallController extends Controller
{
    /** Public: the app was opened, or a sign-in failed on this phone. */
    public function ping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'event' => ['required', Rule::in(['open', 'login_failed'])],
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'device_model' => ['nullable', 'string', 'max:120'],
            'os_version' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'build_number' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'locale' => ['nullable', Rule::in(['en', 'sw'])],
            'login' => ['nullable', 'string', 'max:80'],
        ]);

        $install = MobileInstall::query()->firstOrNew(['installation_id' => $data['installation_id']]);
        $install->fill(array_filter([
            'platform' => $data['platform'] ?? null,
            'device_model' => $data['device_model'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'build_number' => $data['build_number'] ?? null,
            'locale' => $data['locale'] ?? null,
        ], fn ($v) => $v !== null));
        $install->first_seen_at ??= now();
        $install->last_seen_at = now();
        $install->last_ip = $request->ip();
        if ($data['event'] === 'open') {
            $install->opens++;
        } else {
            $install->failed_logins++;
            $install->last_failed_at = now();
            $install->last_failed_login = isset($data['login']) ? mb_substr(trim($data['login']), 0, 80) : null;
        }
        $install->save();

        return $this->ok(['ok' => true]);
    }

    /** The report: downloads, phones, who signed in, who tried, who never tried. */
    public function index(Request $request): JsonResponse
    {
        $hidden = User::developerIds(); // the software engineer's own phones stay out of the report
        $base = fn () => MobileInstall::query()->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $hidden ?: [0]));

        $query = $base()->with(['user:id,name,username,phone,type,role_id', 'user.role:id,name']);
        if ($status = $request->input('status')) {
            match ($status) {
                'signed_in' => $query->where('logins', '>', 0),
                'tried' => $query->where('logins', 0)->where('failed_logins', '>', 0),
                'never' => $query->where('logins', 0)->where('failed_logins', 0),
                'active' => $query->where('last_seen_at', '>=', now()->subDays(7)),
                'inactive' => $query->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(30))),
                default => null,
            };
        }
        if ($type = $request->input('user_type')) {
            $query->whereHas('user', fn ($q) => $q->where('type', $type));
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('device_model', 'like', "%{$search}%")->orWhere('last_failed_login', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")));
        }
        $page = $query->orderByDesc('last_seen_at')->paginate($this->perPage($request));
        $page->getCollection()->transform(fn (MobileInstall $i) => [
            'id' => $i->id,
            'device_model' => $i->device_model,
            'platform' => $i->platform,
            'os_version' => $i->os_version,
            'app_version' => $i->app_version,
            'build_number' => $i->build_number,
            'opens' => $i->opens,
            'first_seen_at' => $i->first_seen_at,
            'last_seen_at' => $i->last_seen_at,
            'status' => $i->status,
            'logins' => $i->logins,
            'failed_logins' => $i->failed_logins,
            'last_login_at' => $i->last_login_at,
            'last_failed_at' => $i->last_failed_at,
            'last_failed_login' => $i->last_failed_login,
            'user' => $i->user ? ['id' => $i->user->id, 'name' => $i->user->name, 'username' => $i->user->username, 'phone' => $i->user->phone, 'type' => $i->user->type, 'role' => $i->user->role?->name] : null,
        ]);

        $latest = AppRelease::query()->where('is_published', true)->orderByDesc('build_number')->first();
        $all = $base();
        $summary = [
            'downloads' => (int) AppRelease::query()->sum('downloads'),
            'latest_version' => $latest?->version,
            'latest_downloads' => (int) ($latest?->downloads ?? 0),
            'installs' => (clone $all)->count(),
            'signed_in' => (clone $all)->where('logins', '>', 0)->count(),
            'tried' => (clone $all)->where('logins', 0)->where('failed_logins', '>', 0)->count(),
            'never' => (clone $all)->where('logins', 0)->where('failed_logins', 0)->count(),
            'active_7_days' => (clone $all)->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'on_latest' => $latest ? (clone $all)->where('build_number', '>=', $latest->build_number)->count() : 0,
            'staff' => (clone $all)->whereHas('user', fn ($q) => $q->where('type', 'staff'))->count(),
            'customers' => (clone $all)->whereHas('user', fn ($q) => $q->where('type', 'customer'))->count(),
            'versions' => (clone $all)->select('app_version', DB::raw('count(*) as phones'))->groupBy('app_version')->orderByDesc('phones')->limit(8)->get(),
        ];

        return $this->paginated($page, ['summary' => $summary]);
    }
}
