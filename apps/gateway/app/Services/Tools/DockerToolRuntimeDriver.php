<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class DockerToolRuntimeDriver implements ToolRuntimeDriver
{
    public function __construct(
        private string $platformFamily,
    ) {}

    public function implementationKey(): string
    {
        return "docker/{$this->platformFamily}";
    }

    public function label(): string
    {
        return 'standalone Docker';
    }
}
