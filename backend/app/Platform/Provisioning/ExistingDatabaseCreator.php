<?php

namespace App\Platform\Provisioning;

/** The database is made by hand (or is an SQLite file, as in tests). */
class ExistingDatabaseCreator implements DatabaseCreator
{
    public function create(string $name): void
    {
        $default = config('database.connections.'.config('database.default'));
        if (($default['driver'] ?? null) === 'sqlite' && ! file_exists($name)) {
            touch($name);
        }
    }
}
