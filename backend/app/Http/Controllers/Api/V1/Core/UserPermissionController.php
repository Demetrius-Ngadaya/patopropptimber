<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Core\Permission;
use App\Models\Core\User;
use App\Services\Core\AuditLogger;
use App\Support\Modules;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Permissions for one user on top of their role: "grant" adds a permission
 * the role does not have, "deny" removes one it does. Other users with the
 * same role are not affected.
 */
class UserPermissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->target($request, $id);
        $overrides = $user->permissionOverrides()->get(['permissions.key']);
        $permissions = Permission::query()->orderBy('sort_order')->get(['id', 'key', 'module', 'action', 'type', 'label']);

        return $this->ok([
            'user' => $user->only(['id', 'name', 'username']) + ['role' => $user->role?->only(['id', 'name', 'is_super_admin'])],
            'role_permissions' => $user->role?->permissions()->pluck('key')->values() ?? [],
            'grants' => $overrides->where('pivot.effect', 'grant')->pluck('key')->values(),
            'denies' => $overrides->where('pivot.effect', 'deny')->pluck('key')->values(),
            'effective' => $user->permissionKeys(),
            'disabled_modules' => Modules::disabled(),
            // The catalogue, so this screen does not need the roles.view permission.
            'modules' => collect(PermissionCatalog::modules())->map(fn ($m, $key) => [
                'module' => $key,
                'label' => $m['label'],
                'permissions' => $permissions->where('module', $key)->values(),
            ])->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->target($request, $id);
        $data = $request->validate([
            'grants' => ['present', 'array'],
            'grants.*' => ['string', 'distinct', Rule::in(PermissionCatalog::keys())],
            'denies' => ['present', 'array'],
            'denies.*' => ['string', 'distinct', Rule::in(PermissionCatalog::keys()), Rule::notIn($request->input('grants', []))],
        ], ['denies.*.not_in' => __('A permission cannot be both given and removed.')]);

        $actor = $request->user();
        if (! $actor->isSuperAdmin()) {
            // You can only hand out (or take away) what you hold yourself.
            $outside = array_diff(array_merge($data['grants'], $data['denies']), $actor->permissionKeys());
            if ($outside !== []) {
                throw new BusinessException(__('You can only give or remove permissions that you have yourself.'), 'FORBIDDEN', 403);
            }
        }

        $roleKeys = $user->role?->permissions()->pluck('key')->all() ?? [];
        // A grant the role already has, or a deny the role does not have, changes nothing: drop it.
        $grants = array_values(array_diff($data['grants'], $roleKeys));
        $denies = array_values(array_intersect($data['denies'], $roleKeys));

        $before = $user->permissionOverrides()->get(['permissions.key'])->mapWithKeys(fn ($p) => [$p->key => $p->pivot->effect])->all();
        $ids = Permission::query()->whereIn('key', array_merge($grants, $denies))->pluck('id', 'key');

        DB::transaction(function () use ($user, $grants, $denies, $ids, $actor) {
            $sync = [];
            foreach ($grants as $key) {
                $sync[$ids[$key]] = ['effect' => 'grant', 'created_by' => $actor->id];
            }
            foreach ($denies as $key) {
                $sync[$ids[$key]] = ['effect' => 'deny', 'created_by' => $actor->id];
            }
            $user->permissionOverrides()->sync($sync);
        });

        $after = array_fill_keys($grants, 'grant') + array_fill_keys($denies, 'deny');
        if ($before != $after) {
            $this->audit->event('user.permissions_changed', "Personal permissions changed for {$user->name}", $user, [
                'granted' => $grants,
                'denied' => $denies,
                'previous' => $before,
            ], 'security');
        }

        return $this->ok([
            'grants' => $grants,
            'denies' => $denies,
            'effective' => $user->fresh()->permissionKeys(),
        ], __('Permissions for :user saved. Other users with the same role are not affected.', ['user' => $user->name]));
    }

    private function target(Request $request, int $id): User
    {
        $user = User::query()->visibleTo($request->user())->where('type', User::TYPE_STAFF)->with('role')->findOrFail($id);
        if (! $this->branch()->canAccessAll() && ! in_array((int) $user->branch_id, $this->branch()->accessibleIds(), true)) {
            abort(404);
        }
        if ($user->id === $request->user()->id) {
            throw new BusinessException(__('You cannot change your own permissions.'), 'FORBIDDEN', 403);
        }
        if ($user->isSuperAdmin()) {
            throw new BusinessException(__('A super admin already has every permission.'), 'SUPER_ADMIN', 422);
        }

        return $user;
    }
}
