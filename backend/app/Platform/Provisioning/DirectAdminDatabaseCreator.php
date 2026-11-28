<?php

namespace App\Platform\Provisioning;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared hosting: DirectAdmin creates the database and attaches the existing
 * business database user (CMD_API_DATABASES, action=create, userlist=…).
 * Uses a DirectAdmin Login Key that only allows CMD_API_DATABASES.
 */
class DirectAdminDatabaseCreator implements DatabaseCreator
{
    public function create(string $name): void
    {
        $c = config('patopro.platform.provisioning.directadmin');
        if (! $c['url'] || ! $c['username'] || ! $c['login_key']) {
            throw new RuntimeException('DirectAdmin is not set up: DIRECTADMIN_URL, DIRECTADMIN_USERNAME and DIRECTADMIN_LOGIN_KEY are needed.');
        }

        // DirectAdmin adds "<account>_" in front of the name itself.
        $prefix = $c['username'].'_';
        if (! str_starts_with($name, $prefix)) {
            throw new RuntimeException("Database names must start with $prefix (set PLATFORM_BUSINESS_DB_PREFIX={$prefix}pp_).");
        }

        $response = Http::withBasicAuth($c['username'], $c['login_key'])
            ->asForm()
            ->withOptions(['verify' => $c['verify_ssl']])
            ->timeout(90)
            ->post($c['url'].'/CMD_API_DATABASES', [
                'action' => 'create',
                'name' => substr($name, strlen($prefix)),
                'userlist' => config('patopro.platform.business_database.username'),
            ]);

        parse_str(trim($response->body()), $out);
        if (! $response->successful() || ($out['error'] ?? '1') !== '0') {
            $text = trim(($out['text'] ?? '').' '.($out['details'] ?? '')) ?: mb_substr(strip_tags($response->body()), 0, 300);
            throw new RuntimeException('DirectAdmin could not create the database: '.$text);
        }
    }
}
