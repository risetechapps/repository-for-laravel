<?php

namespace RiseTechApps\Repository\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BeforeRefreshAllMaterializedViewsJobEvent
{
    use Dispatchable;

    public function __construct()
    {
    }
}
