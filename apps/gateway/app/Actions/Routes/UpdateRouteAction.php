<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\UpdateRouteData;
use App\Models\Route;

/** Gateway callers use normal admission and the shared bounded wait. */
final readonly class UpdateRouteAction
{
    public function __construct(private AdmitRouteOperationAction $admission, private AwaitRouteOperationAction $await) {}

    public function execute(Route $route, UpdateRouteData $data, bool $allowGenerated = false): Route
    {
        return $this->await->route($this->admission->update($route, $data, allowGenerated: $allowGenerated));
    }
}
