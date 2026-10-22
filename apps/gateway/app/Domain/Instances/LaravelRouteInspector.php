<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface LaravelRouteInspector
{
    /** @param non-empty-list<string> $prefixes */
    public function hasCollisions(Instance $instance, array $prefixes): bool;
}
