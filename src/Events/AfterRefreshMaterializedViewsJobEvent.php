<?php

namespace RiseTechApps\Repository\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AfterRefreshMaterializedViewsJobEvent
{
    use Dispatchable;

    public string $nameView;

    public function __construct( string $nameView)
    {
        $this->nameView = $nameView;
    }
}
