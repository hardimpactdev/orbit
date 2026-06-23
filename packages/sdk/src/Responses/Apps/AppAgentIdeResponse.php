<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Apps;

final readonly class AppAgentIdeResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
