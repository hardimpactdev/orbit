<?php

declare(strict_types=1);

namespace App\Domain\Instances\Dependencies;

use InvalidArgumentException;

final readonly class InstanceDependencyScanResult
{
    public function __construct(
        public int $instanceId,
        public ?DependencyScanResult $composer,
        public ?DependencyScanResult $javascript,
        /** @var array<string, self>|null */
        public ?array $subProjects = null,
    ) {
        if (($composer !== null && $composer->ecosystem !== DependencyEcosystem::Composer) || ($javascript !== null && $javascript->ecosystem !== DependencyEcosystem::Npm)) {
            throw new InvalidArgumentException('Instance scans require Composer and JavaScript outcomes in their named slots.');
        }
    }

    public function succeeded(): bool
    {
        if ($this->subProjects !== null) {
            return array_all($this->subProjects, static fn (self $project): bool => $project->succeeded());
        }

        return $this->composer?->succeeded() === true && $this->javascript?->succeeded() === true;
    }
}
