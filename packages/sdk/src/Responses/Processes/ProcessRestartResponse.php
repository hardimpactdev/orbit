<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Processes;

final readonly class ProcessRestartResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
