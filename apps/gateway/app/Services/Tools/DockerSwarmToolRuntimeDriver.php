<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class DockerSwarmToolRuntimeDriver implements ToolRuntimeDriver
{
    public function __construct(
        private string $platformFamily,
    ) {}

    public function implementationKey(): string
    {
        return "docker-swarm/{$this->platformFamily}";
    }

    public function label(): string
    {
        return 'Swarm service';
    }
}
