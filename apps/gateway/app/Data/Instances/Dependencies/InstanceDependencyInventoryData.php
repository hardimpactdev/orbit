<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyScanResult;
use App\Domain\Instances\Dependencies\InstanceDependencyScanResult;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceDependencyInventoryData extends Data
{
    public function __construct(
        public int $instanceId,
        public ?bool $succeeded,
        public DependencyInventoryData $composer,
        public DependencyInventoryData $javascript,
        /** @var array<string, self>|Optional */
        public array|Optional $subProjects,
    ) {}

    /** @param array<string, InstanceDependencyScanResult>|null $subProjects */
    public static function fromResults(int $instanceId, ?DependencyScanResult $composer, ?DependencyScanResult $javascript, ?array $subProjects = null): self
    {
        $projects = [];
        $succeeded = true;
        foreach ($subProjects ?? [] as $path => $result) {
            $projects[$path] = self::fromResults($instanceId, $result->composer, $result->javascript);
            $succeeded = $succeeded && $result->succeeded();
        }

        return new self(
            $instanceId,
            $subProjects !== null ? $succeeded : ($composer === null || $javascript === null ? null : $composer->succeeded() && $javascript->succeeded()),
            DependencyInventoryData::fromResult(DependencyEcosystem::Composer, $composer),
            DependencyInventoryData::fromResult(DependencyEcosystem::Npm, $javascript),
            $subProjects === null ? Optional::create() : $projects,
        );
    }
}
