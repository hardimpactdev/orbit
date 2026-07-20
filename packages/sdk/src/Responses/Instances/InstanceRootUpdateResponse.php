<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Instances;

final readonly class InstanceRootUpdateResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public array $data,
        public array $warnings,
        public bool $artifactsReenacted,
    ) {}
}
