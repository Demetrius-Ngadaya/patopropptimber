<?php

namespace App\Platform\Provisioning;

use Illuminate\Support\Facades\DB;

/** A server where the central database user may create databases (e.g. a VPS). */
class MysqlDatabaseCreator implements DatabaseCreator
{
    public function create(string $name): void
    {
        $central = DB::connection(config('patopro.platform.connection'));
        $central->statement("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $user = (string) config('patopro.platform.business_database.username');
        if ($user !== '' && $user !== config('patopro.platform.database.username')) {
            $host = (string) env('PLATFORM_BUSINESS_DB_GRANT_HOST', 'localhost');
            $central->statement("GRANT ALL PRIVILEGES ON `$name`.* TO ".$central->getPdo()->quote($user).'@'.$central->getPdo()->quote($host));
        }
    }
}
