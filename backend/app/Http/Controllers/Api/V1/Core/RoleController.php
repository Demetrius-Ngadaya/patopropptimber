<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Core\Permission;
use App\Models\Core\Role;
use App\Services\Core\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $roles = $this->applyTrashed(Role::query()->visibleTo($request->user()), $request)
            ->withCount(['users', 'permissions'])
            ->when($request->query('search'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->orderByDesc('is_developer')->orderByDesc('is_super_admin')->orderBy('name')
            ->get();

        return $this->ok($roles);
    }

    /** Permission catalogue grouped by module, for the permission matrix screen. */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('sort_order')->get(['id', 'key', 'module', 'action', 'type', 'label']);
        $modules = collect(PermissionCatalog::modules())->map(fn ($m, $key) => [
            'module' => $key,
            'label' => $m['label'],
            'permissions' => $permissions->where('module', $key)->values(),
        ])->values();

        return $this->ok($modules);
    }

    public function show(int $id): JsonResponse
    {
        $role = Role::withTrashed()->visibleTo(auth()->user())->withCount('users')->findOrFail($id);

        return $this->ok($role->toArray() + ['permission_keys' => $role->permissions()->pluck('key')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'all_branches' => $data['all_branches'] ?? false,
            ]);
            $this->syncPermissions($role, $data['permissions'] ?? []);

            return $role;
        });

        return $this->created($role->loadCount(['users', 'permissions']), __('Role created successfully.'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::query()->visibleTo($request->user())->findOrFail($id);
        $data = $this->validated($request, $role);

        if ($role->is_super_admin && array_key_exists('permissions', $data)) {
            unset($data['permissions']); // super admin always has everything
        }

        DB::transaction(function () use ($role, $data) {
            $role->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'all_branches' => $role->is_super_admin ? true : ($data['all_branches'] ?? false),
            ])->save();
            if (array_key_exists('permissions', $data)) {
                $this->syncPermissions($role, $data['permissions']);
            }
        });

        return $this->ok($role->fresh()->loadCount(['users', 'permissions']), __('Role updated successfully.'));
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::query()->visibleTo(auth()->user())->withCount('users')->findOrFail($id);
        if ($role->is_system) {
            throw new BusinessException(__('Built-in roles cannot be deleted. You can change their permissions instead.'));
        }
        if ($role->users_count > 0) {
            throw new BusinessException(__(':users_count user(s) still have this role. Give them another role first.', ['users_count' => $role->users_count]));
        }
        $role->delete();

        return $this->ok(null, __('Role deleted. It can be restored from the deleted items list.'));
    }

    public function restore(int $id): JsonResponse
    {
        $role = Role::onlyTrashed()->visibleTo(auth()->user())->findOrFail($id);
        $role->restore();

        return $this->ok($role, __('Role restored successfully.'));
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->ignore($role?->id)->whereNull('deleted_at')->when(! $request->user()?->isDeveloper(), fn ($r) => $r->where('is_developer', false))],
            'description' => ['nullable', 'string', 'max:255'],
            'all_branches' => ['boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::keys())],
        ]);
    }

    private function syncPermissions(Role $role, array $keys): void
    {
        $before = $role->permissions()->pluck('key')->all();
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
        $role->permissions()->sync($ids);
        $after = Permission::query()->whereIn('id', $ids)->pluck('key')->all();

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added || $removed) {
            $this->audit->event('role.permissions_changed', "Permissions changed for role {$role->name}", $role, [
                'added' => $added,
                'removed' => $removed,
            ], 'security');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 2;
        while (Role::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
