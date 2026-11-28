<?php

use App\Platform\Support\BusinessManager;
use App\Platform\Support\CurrentBusiness;
use App\Platform\Support\PlanAccess;

if (! function_exists('business')) {
    /** The business this request works for (single mode: the one business). */
    function business(): CurrentBusiness
    {
        return app(BusinessManager::class)->current();
    }
}

if (! function_exists('plan')) {
    /** What the current business's package allows. */
    function plan(): PlanAccess
    {
        return business()->plan();
    }
}
