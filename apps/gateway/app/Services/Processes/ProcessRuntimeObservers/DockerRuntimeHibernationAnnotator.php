<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\ProcessRestartPolicy;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class DockerRuntimeHibernationAnnotator
{
    public function __construct(
        private DockerRuntimeHibernationScopes $hibernationScopes,
        private DockerRuntimeHibernationLookup $hibernationLookup,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    /**
     * @param  list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>  $expectedUnits
     * @param  array<string, array<string, mixed>>  $runtimeUnits
     * @return array<string, array<string, mixed>>
     */
    public function annotate(
        Process $process,
        Node $node,
        array $expectedUnits,
        array $runtimeUnits,
    ): array {
        $process->loadMissing(['owner', 'instance']);
        $instance = $process->instance;

        if ($process->owner instanceof Node || ! $instance instanceof Instance) {
            return $runtimeUnits;
        }

        if (
            $process->restart_policy !== ProcessRestartPolicy::Always
            || ! $this->nodeRoleAssignments->nodeHasActiveRole(
                $node,
                NodeRoleName::AppDevelopment->value,
            )
        ) {
            return $runtimeUnits;
        }

        $scopeKeysByUnit = $this->hibernationScopes->byStoppedUnit(
            $process,
            $instance,
            $expectedUnits,
            $runtimeUnits,
        );

        if ($scopeKeysByUnit === []) {
            return $runtimeUnits;
        }

        $hibernatedKeys = $this->hibernationLookup->hibernatedKeys(
            $node,
            array_values(array_unique($scopeKeysByUnit)),
        );

        if ($hibernatedKeys === null) {
            return $runtimeUnits;
        }

        $hibernatedUnitNames = array_keys(array_filter(
            $scopeKeysByUnit,
            static fn (string $scopeKey): bool => in_array($scopeKey, $hibernatedKeys, strict: true),
        ));

        foreach ($hibernatedUnitNames as $unitName) {
            $runtimeUnits[$unitName]['hibernated'] = true;
        }

        return $runtimeUnits;
    }
}
