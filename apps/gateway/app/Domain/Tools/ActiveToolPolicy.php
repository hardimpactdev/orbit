<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final readonly class ActiveToolPolicy
{
    /** @param array<string, ToolDefinition> $packages */
    public function __construct(public string $mode, public ?string $catalogPath, public array $packages = []) {}

    public function definition(string $manager, string $package): ?ToolDefinition
    {
        return $this->packages[$manager.':'.$package] ?? null;
    }
}
