<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\SetRouteTargetsData;
use App\Models\Route;

/** The target-set executor lives in Swamp; retained callers await its proven result. */
final readonly class ConvergeRouteTargetSetAction
{
    public function __construct(private AdmitRouteOperationAction $admission, private AwaitRouteOperationAction $await) {}

    public function execute(Route $route, SetRouteTargetsData $proposal): Route
    {
        return $this->await->route($this->admission->setTargets($route, $proposal));
    }
}
