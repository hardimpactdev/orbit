<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use App\Support\ValidatedData;

/**
 * Records ownership of a package the Node agent reports as installed (R1: no task, no run). It installs, updates,
 * removes and repins nothing, and reads nothing but the agent's latest `packages` fact. The caller holds the
 * admission transaction.
 */
final readonly class ToolAdoption
{
    public function __construct(private VersionConstraint $constraints) {}

    /**
     * @param  array<string, mixed>|null  $repository
     * @return array{Tool, ToolOutcome} `unchanged` when the Tool was already recorded as installed
     */
    public function record(Node $node, ToolManager $manager, ?ToolManagerRecord $record, ?Tool $tool, string $package, ?string $constraint, ?array $repository): array
    {
        $observed = ObservedPackages::find($node, $manager->name())
            ?? throw new ResourceOperationException('tool.not_observed', "The Node agent has not reported the {$manager->name()->value} packages on this Node yet.", 409);
        $found = $observed->packages[$package] ?? throw new ResourceOperationException('tool.package_absent', 'The package is not installed.', 409);
        if ($found->block !== null) {
            throw new ToolAdoptionBlocked($found->block);
        }
        $version = (string) $found->version();
        if ($constraint !== null) {
            $normalized = $manager->normalizeVersion($version)
                ?? throw new ResourceOperationException('tool.installed_version_unparseable', 'The installed version cannot be compared with the constraint.', 409);
            if (! $this->constraints->allows($normalized, $constraint)) {
                throw new ResourceOperationException('tool.installed_version_constraint_violated', 'The installed version is outside the constraint.', 409);
            }
        }
        if ($repository !== null && ! $this->repositoryMatches($observed, $repository)) {
            throw new ResourceOperationException('tool.repository_adoption_mismatch', 'The apt source or signing key on the Node does not match the repository.', 409);
        }

        $record ??= ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => $manager->name()->value, 'status' => LifecycleStatus::Active]);
        $repositoryId = null;
        if ($repository !== null) {
            $repositoryRecord = AptRepository::query()->firstOrCreate(['node_id' => $node->id, 'name' => $repository['name']], ['definition' => $repository]);
            if ($repositoryRecord->definition != $repository) {
                $repositoryRecord->update(['definition' => $repository]);
            }
            $repositoryId = $repositoryRecord->id;
        }
        $outcome = $tool?->status === ToolStatus::Installed ? ToolOutcome::Unchanged : ToolOutcome::Applied;
        $tool ??= new Tool(['node_id' => $node->id, 'tool_manager_id' => $record->id, 'package' => $package, 'version_constraint' => $constraint]);
        $tool->fill(['status' => ToolStatus::Installed, 'installed_version' => $version, 'apt_repository_id' => $repositoryId ?? $tool->apt_repository_id,
            'failed_operation' => null, 'error_code' => null])->save();

        return [$tool, $outcome];
    }

    /**
     * The source and keyring Orbit's install writes for this repository: the deb822 source with these values and a
     * keyring whose only primary key has the admitted fingerprint.
     *
     * @param  array<string, mixed>  $repository
     */
    private function repositoryMatches(ObservedPackages $observed, array $repository): bool
    {
        $name = 'orbit-'.ValidatedData::string($repository['name'] ?? null);
        $join = static fn (string $field, array $default = []): string => implode(' ', ValidatedData::stringList($repository[$field] ?? $default));
        $source = "Types: deb\nURIs: {$join('uris')}\nSuites: {$join('suites')}\nComponents: {$join('components')}\n"
            ."Architectures: {$join('architectures', ['amd64'])}\nSigned-By: /etc/apt/keyrings/{$name}.gpg\n";
        $keyring = $observed->file("/etc/apt/keyrings/{$name}.gpg");

        return $observed->file("/etc/apt/sources.list.d/{$name}.sources") === $source && $keyring !== null
            && OpenPgpPrimaryKeys::fingerprints($keyring) === [ValidatedData::object($repository['signing_key'] ?? null)['fingerprint'] ?? null];
    }
}
