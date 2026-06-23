<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Tools;

final readonly class ToolReconfigureResponse
{
    /**
     * @param  array<string, mixed>  $tool
     */
    public function __construct(
        public array $tool,
    ) {}
}
