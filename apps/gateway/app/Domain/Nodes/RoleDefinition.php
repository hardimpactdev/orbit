<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final readonly class RoleDefinition
{
    /**
     * @param  list<RoleName>  $conflicts
     * @param  bool  $metrics  Whether the role takes part in Metrics: its transitions converge the fleet and it selects the role-default exporter.
     */
    public function __construct(
        public RoleName $name,
        public bool $singleton,
        public bool $assignableDuringProvisioning,
        public bool $mutable,
        public bool $relocatable,
        public array $conflicts = [],
        public bool $metrics = true,
    ) {}
}
