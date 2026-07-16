<?php

namespace RiseTechApps\Repository\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BeforeRefreshMaterializedViewsJobEvent
{
    use Dispatchable;

    public function __construct(public string $nameView)
    {
    }
}
