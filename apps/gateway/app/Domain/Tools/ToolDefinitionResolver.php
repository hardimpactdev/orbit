<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Tools\AptRepositoryRules;
use App\Infrastructure\MachineSoftware\ConnServerReleases;
use App\Models\Tool;

final readonly class ToolDefinitionResolver
{
    public function __construct(private ToolPolicy $policy, private VersionConstraint $constraints, private ConnServerReleases $conn) {}

    /** @param array<string, mixed>|null $repository */
    public function resolve(string $verb, ToolManager $manager, string $package, ?Tool $tool, ?string $version, ?string $constraint, ?array $repository, ?ActiveToolPolicy $active = null): ToolDefinition
    {
        if ($verb === 'remove') {
            return new ToolDefinition($tool?->desired_version, $tool?->version_constraint, null);
        }
        $active ??= $this->policy->active();
        if ($active->mode === 'file') {
            if ($version !== null || $constraint !== null || $repository !== null) {
                throw new ResourceOperationException('tool.file_override', 'File mode takes the coordinate, constraint and source only from its catalog.', 409);
            }
            $definition = $active->definition($manager->name()->value, $package)
                ?? throw new ResourceOperationException('tool.not_in_catalog', 'The package is not in the active Tool catalog.', 409);
        } else {
            if ($tool !== null && $constraint !== null && $tool->version_constraint !== $constraint) {
                throw new ResourceOperationException('tool.constraint_conflict', 'The Tool already has a different constraint.', 409);
            }
            $pin = $version ?? $tool->desired_version ?? ($manager->name() === ToolManagerName::ConnServer ? $this->conn->find(null)?->version : null);
            $definition = new ToolDefinition($pin, $constraint ?? $tool?->version_constraint, $repository ?? $tool->desired_repository ?? $tool?->aptRepository?->definition);
        }
        self::assertRepositoryIdentity($tool, $definition);
        $this->validate($manager, $definition);

        return $definition;
    }

    public static function assertRepositoryIdentity(?Tool $tool, ToolDefinition $definition): void
    {
        if ($tool?->aptRepository !== null && ($definition->repository['name'] ?? null) !== $tool->aptRepository->name) {
            throw new ResourceOperationException('tool.repository_conflict', 'Remove the Tool membership before changing its repository name.', 409);
        }
    }

    public function validate(ToolManager $manager, ToolDefinition $definition): void
    {
        if (! $this->constraints->isValid($definition->constraint)) {
            throw new ResourceOperationException('tool.constraint_invalid', 'The Tool constraint is invalid.', 422);
        }
        $version = $definition->version;
        if ($version !== null) {
            $pattern = match ($manager->name()) {
                ToolManagerName::Apt => '/\A(?:[0-9]+:)?[0-9][0-9A-Za-z.+~:-]{0,253}\z/D',
                ToolManagerName::BrewCask => '/\A[0-9][0-9A-Za-z.+_,:-]{0,254}\z/D',
                ToolManagerName::Brew => '/\A[0-9][0-9A-Za-z.+_-]{0,254}\z/D',
                ToolManagerName::ConnServer => '/\A[0-9]+\.[0-9]+\.[0-9]+\z/D',
                ToolManagerName::Vp, ToolManagerName::Composer => '/\Av?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D',
            };
            if (strlen($version) > 255 || preg_match($pattern, $version) !== 1
                || (in_array($manager->name(), [ToolManagerName::Vp, ToolManagerName::Composer], true) && $manager->normalizeVersion($version) === null)) {
                throw new ResourceOperationException('tool.version_invalid', 'The exact Tool coordinate is invalid for this manager.', 422);
            }
            if ($definition->constraint !== null) {
                $normalized = $manager->normalizeVersion($version);
                if ($normalized === null || ! $this->constraints->allows($normalized, $definition->constraint)) {
                    throw new ResourceOperationException('tool.version_constraint_blocked', 'The exact Tool coordinate is outside the constraint.', 422);
                }
            }
        }
        if ($manager->name() === ToolManagerName::ConnServer && ($version === null || $this->conn->find($version) === null)) {
            throw new ResourceOperationException('conn_server.pin_unavailable', 'The requested Conn Server host release is not in the release catalog.', 409);
        }
        new AptRepositoryRules()->validate($definition->repository, $manager->name()->value);
    }
}
