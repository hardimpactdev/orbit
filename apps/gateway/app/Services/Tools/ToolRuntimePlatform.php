<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRuntimePlatform
{
    public function __construct(
        public string $nodePlatform,
        public string $platformFamily,
    ) {}

    public function implementationKey(string $runtime): string
    {
        return "{$runtime}/{$this->platformFamily}";
    }
}
