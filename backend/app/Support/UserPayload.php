<?php

namespace App\Support;

use App\Models\Core\Branch;
use App\Models\Core\User;
use App\Services\Core\SettingsService;
use App\Services\Core\TwoFactorService;

/**
 * What the frontend needs to know about the signed-in user.
 */
final class UserPayload
{
    public static function make(User $user): array
    {
        $user->loadMissing(['role', 'branch', 'customer']);
        $settings = app(SettingsService::class);

        $base = [
            'id' => $user->id,
            'type' => $user->type,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'must_change_password' => $user->must_change_password,
            'locale' => $user->locale ?: 'en',
            'theme' => $user->theme ?: 'system',
            'two_factor_enabled' => $user->hasTwoFactor(),
            'two_factor_required' => TwoFactorService::requiredFor($user),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];

        if ($user->isCustomer()) {
            return $base + [
                'customer' => $user->customer?->only(['id', 'code', 'name', 'phone', 'email', 'address', 'city', 'balance']),
                'session_timeout_minutes' => $settings->int('customer_session_timeout_minutes'),
            ];
        }

        $branches = $user->canAccessAllBranches()
            ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
            : Branch::query()->whereKey($user->branch_id)->get(['id', 'name', 'code']);

        return $base + [
            'role' => $user->role ? $user->role->only(['id', 'name', 'slug', 'is_super_admin', 'is_developer']) : null,
            'branch' => $user->branch?->only(['id', 'name', 'code']),
            'all_branches' => $user->canAccessAllBranches(),
            'branches' => $branches,
            'permissions' => $user->permissionKeys(),
            'disabled_modules' => Modules::disabled(),
            'session_timeout_minutes' => $settings->int('session_timeout_minutes'),
        ];
    }
}
