<?php

namespace App\Platform\Provisioning;

/** Makes an empty database for a new business, granted to the shared business database user. */
interface DatabaseCreator
{
    /** @param  string  $name  the full database name, e.g. shezonic_pp_duka_la_juma */
    public function create(string $name): void;
}
