<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Core\LoginLog;
use App\Models\Core\Role;
use App\Models\Core\User;
use App\Services\Core\AuditLogger;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->applyTrashed(User::query()->visibleTo($request->user()), $request)
            ->where('type', User::TYPE_STAFF)
            ->with(['role:id,name,is_super_admin,is_developer', 'branch:id,name'])
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")
                ->orWhere('username', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->when($request->query('role_id'), fn ($q, $v) => $q->where('role_id', $v))
            ->when($request->query('branch_id'), fn ($q, $v) => $q->where('branch_id', $v))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        // Branch admins only manage people in branches they can access.
        if (! $this->branch()->canAccessAll()) {
            $query->whereIn('branch_id', $this->branch()->accessibleIds());
        }

        $this->sortable($query, $request, ['name', 'username', 'last_login_at', 'created_at'], 'name', 'asc');

        if ($request->boolean('all')) {
            return $this->ok($query->limit(500)->get(['id', 'name', 'username', 'phone', 'branch_id', 'role_id']));
        }

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function show(int $id): JsonResponse
    {
        $user = User::withTrashed()->visibleTo(auth()->user())->where('type', User::TYPE_STAFF)->with(['role', 'branch', 'creator'])->findOrFail($id);
        $this->guardScope($user);

        return $this->ok($user->toArray() + [
            'active_sessions' => $user->tokens()->count(),
            'is_locked' => $user->isLocked(),
            'recent_logins' => LoginLog::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guardRoleAssignment($request->user(), $data);

        $user = User::create($data + ['type' => User::TYPE_STAFF, 'password_changed_at' => now()]);

        return $this->created($user->load(['role:id,name', 'branch:id,name']), __('User :user created successfully.', ['user' => $user->name]));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->visibleTo(auth()->user())->where('type', User::TYPE_STAFF)->findOrFail($id);
        $this->guardScope($user);
        $data = $this->validated($request, $user);
        $this->guardRoleAssignment($request->user(), $data, $user);

        if ($user->id === $request->user()->id && isset($data['is_active']) && ! $data['is_active']) {
            throw new BusinessException(__('You cannot deactivate your own account.'));
        }

        $user->fill($data)->save();
        if (isset($data['is_active']) && ! $data['is_active']) {
            $this->revokeTokens($user, 'revoked');
        }

        return $this->ok($user->fresh(['role:id,name', 'branch:id,name']), __('User updated successfully.'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::query()->visibleTo(auth()->user())->where('type', User::TYPE_STAFF)->findOrFail($id);
        $this->guardScope($user);
        if ($user->id === $request->user()->id) {
            throw new BusinessException(__('You cannot delete your own account.'));
        }
        if ($user->isSuperAdmin() && ! $user->isDeveloper() && User::query()->whereHas('role', fn ($q) => $q->where('is_super_admin', true)->where('is_developer', false))->where('is_active', true)->count() <= 1) {
            throw new BusinessException(__('This is the last active super admin and cannot be deleted.'));
        }

        $this->revokeTokens($user, 'revoked');
        $user->delete();

        return $this->ok(null, __('User deleted. They can no longer log in. The account can be restored.'));
    }

    public function restore(int $id): JsonResponse
    {
        $user = User::onlyTrashed()->visibleTo(auth()->user())->where('type', User::TYPE_STAFF)->findOrFail($id);
        $user->restore();

        return $this->ok($user, __('User restored successfully.'));
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = User::query()->visibleTo(auth()->user())->where('type', User::TYPE_STAFF)->findOrFail($id);
        $this->guardScope($user);
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
            'must_change_password' => ['boolean'],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => $data['must_change_password'] ?? true,
            'password_changed_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
        $this->revokeTokens($user, 'revoked');
        $this->audit->event('user.password_reset', "Password reset for {$user->name}", $user, [], 'security');

        return $this->ok(null, __('Password for :user has been reset.', ['user' => $user->name]));
    }

    public function unlock(int $id): JsonResponse
    {
        $user = User::query()->visibleTo(auth()->user())->findOrFail($id);
        $this->guardScope($user);
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();
        $this->audit->event('user.unlocked', __('Account :user unlocked', ['user' => $user->name]), $user, [], 'security');

        return $this->ok(null, __(':user can log in again.', ['user' => $user->name]));
    }

    /** For a user who lost the phone with their authenticator app. */
    public function resetTwoFactor(Request $request, int $id, TwoFactorService $twoFactor): JsonResponse
    {
        $user = User::query()->visibleTo(auth()->user())->where('type', User::TYPE_STAFF)->findOrFail($id);
        $this->guardScope($user);
        if ($user->id === $request->user()->id) {
            throw new BusinessException(__('Use your own profile to change your two-factor login.'));
        }
        $twoFactor->reset($user);
        $this->revokeTokens($user, 'revoked');
        $this->audit->event('security.2fa_reset', __('Two-factor login of :user reset by an administrator', ['user' => $user->name]), $user, [], 'security');

        return $this->ok(null, __('Two-factor login of :user has been reset. They must set it up again.', ['user' => $user->name]));
    }

    public function forceLogout(int $id): JsonResponse
    {
        $user = User::query()->visibleTo(auth()->user())->findOrFail($id);
        $this->guardScope($user);
        $count = $this->revokeTokens($user, 'revoked');
        $this->audit->event('user.force_logout', "Forced logout of {$user->name} ({$count} session(s))", $user, [], 'security');

        return $this->ok(null, __(':user has been logged out from :count device(s).', ['user' => $user->name, 'count' => $count]));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'alpha_dash', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user?->id)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', 'required_unless:all_branches,true', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'all_branches' => ['boolean'],
            'is_active' => ['boolean'],
            'must_change_password' => ['boolean'],
        ];
        $rules['password'] = $user
            ? ['nullable', 'confirmed', Password::defaults()]
            : ['required', 'confirmed', Password::defaults()];

        $data = $request->validate($rules, [
            'branch_id.required_unless' => __('Choose a branch, or give the user access to all branches.'),
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password_changed_at'] = now();
        }
        unset($data['password_confirmation']);

        return $data;
    }

    /** Non-super-admins cannot create super admins or grant all-branch access. */
    private function guardRoleAssignment(User $actor, array $data, ?User $target = null): void
    {
        $role = Role::find($data['role_id'] ?? $target?->role_id);
        // The Software Engineer role does not exist for anyone else: an unknown role.
        if (! $actor->isDeveloper() && ($role?->is_developer || $target?->isDeveloper())) {
            throw ValidationException::withMessages(['role_id' => __('Choose a valid role.')]);
        }
        if ($actor->isSuperAdmin()) {
            return;
        }
        if ($role?->is_super_admin) {
            throw new BusinessException(__('Only a super admin can assign the Super Admin role.'), 'FORBIDDEN', 403);
        }
        if (! empty($data['all_branches']) && ! $actor->canAccessAllBranches()) {
            throw new BusinessException(__('You cannot give access to all branches.'), 'FORBIDDEN', 403);
        }
        if (! $this->branch()->canAccessAll() && isset($data['branch_id']) && ! $this->branch()->canAccess((int) $data['branch_id'])) {
            throw new BusinessException(__('You can only add users to your own branch.'), 'FORBIDDEN', 403);
        }
        if ($target?->isSuperAdmin()) {
            throw new BusinessException(__('Only a super admin can change a super admin account.'), 'FORBIDDEN', 403);
        }
    }

    private function guardScope(User $user): void
    {
        if (! $this->branch()->canAccessAll() && ! in_array((int) $user->branch_id, $this->branch()->accessibleIds(), true)) {
            abort(404);
        }
    }

    private function revokeTokens(User $user, string $reason): int
    {
        $ids = $user->tokens()->pluck('id');
        LoginLog::query()->whereIn('token_id', $ids)->whereNull('logged_out_at')->update(['logged_out_at' => now(), 'logout_reason' => $reason]);

        return $user->tokens()->delete();
    }
}
