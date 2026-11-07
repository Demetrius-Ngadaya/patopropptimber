<?php

namespace App\Models\Core;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUserstamps;
use App\Models\Crm\Customer;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, HasUserstamps, Notifiable, SoftDeletes;

    public const TYPE_STAFF = 'staff';

    public const TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'type', 'name', 'username', 'email', 'phone', 'password', 'role_id', 'branch_id',
        'all_branches', 'is_active', 'must_change_password', 'avatar_path', 'locale', 'theme',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'];

    /** @var array<int, string> */
    protected array $auditExclude = ['last_login_at', 'last_login_ip', 'failed_login_attempts', 'password_changed_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'];

    /** Memoised permission keys for this request. */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'all_branches' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function isStaff(): bool
    {
        return $this->type === self::TYPE_STAFF;
    }

    public function isCustomer(): bool
    {
        return $this->type === self::TYPE_CUSTOMER;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isStaff() && $this->role && ! $this->role->trashed() && $this->role->is_super_admin;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** The hidden Software Engineer role: sees everything, is seen by nobody else. */
    public function isDeveloper(): bool
    {
        return $this->isStaff() && $this->role && ! $this->role->trashed() && $this->role->is_developer;
    }

    /** Only software engineers may see or manage software engineer accounts. */
    /** @return int[] ids of Software Engineer accounts (hidden from everyone else) */
    public static function developerIds(): array
    {
        return static::withTrashed()->whereHas('role', fn ($q) => $q->withTrashed()->where('is_developer', true))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $viewer?->isDeveloper() ? $query : $query->whereDoesntHave('role', fn ($q) => $q->where('is_developer', true));
    }

    /** @return BelongsToMany<Permission> per-user exceptions; pivot.effect is grant or deny */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')->withPivot(['effect', 'created_by'])->withTimestamps();
    }

    /**
     * Effective permissions: the role's permissions (all of them for super admins),
     * plus this user's grants, minus this user's denials, minus switched-off modules.
     *
     * @return array<string>
     */
    public function permissionKeys(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        if (! $this->isStaff() || ! $this->role || $this->role->trashed()) {
            return $this->permissionCache = [];
        }

        if ($this->isDeveloper()) {
            return $this->permissionCache = Permission::query()->pluck('key')->all();
        }

        if ($this->role->is_super_admin) {
            // Super admins always have every permission of every module that is switched on.
            return $this->permissionCache = Modules::filter(Permission::query()->pluck('key')->all());
        }

        $keys = $this->role->permissions()->pluck('key')->all();
        $overrides = $this->permissionOverrides()->get(['permissions.key']);
        $grants = $overrides->where('pivot.effect', 'grant')->pluck('key')->all();
        $denies = $overrides->where('pivot.effect', 'deny')->pluck('key')->all();
        $keys = array_values(array_diff(array_unique(array_merge($keys, $grants)), $denies));

        return $this->permissionCache = Modules::filter($keys);
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function hasAnyPermission(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function canAccessAllBranches(): bool
    {
        return $this->isSuperAdmin() || $this->all_branches || ($this->role && $this->role->all_branches);
    }

    /** @return array<int> */
    public function accessibleBranchIds(): array
    {
        if ($this->canAccessAllBranches()) {
            return Branch::query()->where('is_active', true)->pluck('id')->all();
        }

        return $this->branch_id ? [(int) $this->branch_id] : [];
    }
}
