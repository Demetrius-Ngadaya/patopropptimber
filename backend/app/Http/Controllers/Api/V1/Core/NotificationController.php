<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The bell: notifications the signed-in user may see (by permission and branch). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = AppNotification::query()->visibleTo($user)
            ->when($request->boolean('unread'), fn ($q) => $q->unreadBy($user))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->withExists(['readers as is_read' => fn ($q) => $q->whereKey($user->id)])
            ->latest('id')
            ->paginate($this->perPage($request, 30));
        $page->setCollection($page->getCollection()->map(fn (AppNotification $n) => $n->present((bool) $n->is_read)));

        return $this->paginated($page);
    }

    /** Unread count and the latest few, for the bell in the top bar. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = AppNotification::query()->visibleTo($user)
            ->withExists(['readers as is_read' => fn ($q) => $q->whereKey($user->id)])
            ->latest('id')->limit(8)->get();

        return $this->ok([
            'unread' => AppNotification::query()->visibleTo($user)->unreadBy($user)->count(),
            'latest' => $latest->map(fn (AppNotification $n) => $n->present((bool) $n->is_read))->values(),
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $notification = AppNotification::query()->visibleTo($request->user())->findOrFail($id);
        $notification->readers()->syncWithoutDetaching([$request->user()->id => ['read_at' => now()]]);

        return $this->ok(null);
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = AppNotification::query()->visibleTo($user)->unreadBy($user)->pluck('id');
        DB::table('app_notification_reads')->insertOrIgnore($ids->map(fn ($id) => ['app_notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all());

        return $this->ok(['marked' => $ids->count()], __('All notifications marked as read.'));
    }
}
