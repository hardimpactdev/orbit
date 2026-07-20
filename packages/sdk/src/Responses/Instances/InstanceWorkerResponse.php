<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Instances;

final readonly class InstanceWorkerResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
