<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Instances;

final readonly class InstanceListResponse
{
    /**
     * @param  list<array<string, mixed>>  $instances
     */
    public function __construct(
        public array $instances,
    ) {}
}
