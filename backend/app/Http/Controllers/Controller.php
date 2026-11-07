<?php

namespace App\Http\Controllers;

use App\Support\BranchContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Consistent success envelope: { success, message, data }. */
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], fn ($v) => $v !== null), $status);
    }

    protected function created(mixed $data, string $message): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function paginated(LengthAwarePaginator $paginator, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], $extra));
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return min(max((int) $request->integer('per_page', $default), 5), 200);
    }

    /** ?trashed=only shows deleted records, ?trashed=with shows both. */
    protected function applyTrashed(Builder $query, Request $request): Builder
    {
        return match ($request->query('trashed')) {
            'only' => $query->onlyTrashed(),
            'with' => $query->withTrashed(),
            default => $query,
        };
    }

    protected function sortable(Builder $query, Request $request, array $allowed, string $default = 'id', string $direction = 'desc'): Builder
    {
        $sort = $request->query('sort', $default);
        $dir = strtolower((string) $request->query('direction', $direction)) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(in_array($sort, $allowed, true) ? $sort : $default, $dir);
    }

    protected function branch(): BranchContext
    {
        return app(BranchContext::class);
    }

    protected function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'You do not have permission to perform this action.');
    }
}
