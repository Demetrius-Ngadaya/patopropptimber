<?php

namespace App\Http\Middleware;

use App\Models\Core\ActivityLog;
use App\Services\Core\SettingsService;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every action a signed-in user performs: what (route), where (branch),
 * from where (IP / device), the outcome (status code) and the submitted data
 * with secrets removed. Read requests are recorded when the admin enables it,
 * and always for exports.
 */
class LogActivity
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly BranchContext $branch,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        try {
            $this->record($request, $response, $started);
        } catch (Throwable $e) {
            Log::error('Activity log failed: '.$e->getMessage());
        }

        return $response;
    }

    private function record(Request $request, Response $response, float $started): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $isExport = $request->has('export') || Str::contains($request->path(), ['export', 'download']);
        if ($isRead && ! $isExport && ! $this->settings->bool('log_read_requests')) {
            return;
        }

        $routeName = $request->route()?->getName();

        ActivityLog::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_type' => $user->type,
            'branch_id' => $this->branch->id(),
            'method' => $request->method(),
            'route_name' => $routeName,
            'action' => $routeName ? Str::headline(str_replace('.', ' ', $routeName)) : null,
            'path' => Str::limit($request->path(), 495, ''),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            // Personal notes are private: the log records the action, never the text.
            'payload' => $routeName && str_starts_with($routeName, 'admin.notes.') ? null
                : ($isRead ? ($request->query() ?: null) : $this->sanitize($request->except(array_keys($request->allFiles())))),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 495, ''),
        ]);
    }

    private function sanitize(array $data): ?array
    {
        if ($data === []) {
            return null;
        }

        $redact = config('mauzo.audit.redact', []);
        $walk = function (array $items) use (&$walk, $redact) {
            foreach ($items as $key => $value) {
                if (in_array(strtolower((string) $key), $redact, true)) {
                    $items[$key] = '[hidden]';
                } elseif (is_array($value)) {
                    $items[$key] = $walk($value);
                } elseif (is_string($value) && strlen($value) > 500) {
                    $items[$key] = substr($value, 0, 500).'…';
                }
            }

            return $items;
        };

        $clean = $walk($data);
        $json = json_encode($clean);

        // Keep very large payloads (e.g. 500-line imports) from bloating the log.
        return strlen((string) $json) > 20000 ? ['_truncated' => true, 'keys' => array_keys(Arr::dot($clean))] : $clean;
    }
}
