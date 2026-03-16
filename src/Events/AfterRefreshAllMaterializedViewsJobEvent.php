<?php

namespace RiseTechApps\Repository\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AfterRefreshAllMaterializedViewsJobEvent
{
    use Dispatchable;

    public function __construct()
    {
    }
}
