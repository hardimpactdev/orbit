<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Tools;

final readonly class ToolListResponse
{
    /**
     * @param  list<array<string, mixed>>  $tools
     */
    public function __construct(
        public array $tools,
    ) {}
}
