<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final readonly class ActiveToolPolicy
{
    /**
     * @param  array<string, ToolDefinition>  $packages
     * @param  array<string, array<string, mixed>>  $repositories  the catalog's apt repository definitions, by name
     */
    public function __construct(public string $mode, public ?string $catalogPath, public array $packages = [], public array $repositories = []) {}

    public function definition(string $manager, string $package): ?ToolDefinition
    {
        return $this->packages[$manager.':'.$package] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function repository(string $name): ?array
    {
        return $this->repositories[$name] ?? null;
    }
}
