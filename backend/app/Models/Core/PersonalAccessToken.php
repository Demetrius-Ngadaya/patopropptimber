<?php

namespace App\Models\Core;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

class PersonalAccessToken extends SanctumToken
{
    /** Logins from the PatoPro mobile app: longer lifetime, their own idle rule. */
    public const MOBILE_PREFIX = 'mobile: ';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'ip_address', 'user_agent', 'last_activity_at'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'last_activity_at' => 'datetime',
        ]);
    }

    public function isMobile(): bool
    {
        return str_starts_with((string) $this->name, self::MOBILE_PREFIX);
    }
}
