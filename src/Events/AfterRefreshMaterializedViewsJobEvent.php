<?php

namespace RiseTechApps\Repository\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AfterRefreshMaterializedViewsJobEvent
{
    use Dispatchable;

    public function __construct(public string $nameView){
    }
}
