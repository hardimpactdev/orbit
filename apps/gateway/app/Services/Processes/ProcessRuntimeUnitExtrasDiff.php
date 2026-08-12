<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessRuntimeUnitExtrasDiff
{
    private const array WORKSPACE_OWNER_TYPES = [Workspace::class, 'workspace'];

    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ProcessRuntimeUnitDetail $runtimeUnitDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ! is_array($observed['runtime_unit_extras'] ?? null)
        ) {
            return [];
        }

        $process->loadMissing('owner');

        if (
            $process->owner instanceof Node
            && $this->runtimeContextResolver->runtime($process) === ProcessRuntime::Systemd
        ) {
            return [];
        }

        $isDocker = in_array(
            $this->runtimeContextResolver->runtime($process),
            [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
            strict: true,
        );
        $runtimeUnitPrefix = $this->runtimeUnitPrefix($process);
        $expectedRuntimeUnitsForApp = $this->expectedRuntimeUnitsForApp($process);

        $drift = collect($observed['runtime_unit_extras'])
            ->filter(static fn (mixed $runtimeUnit): bool => is_string($runtimeUnit) && $runtimeUnit !== '')
            ->reject(static fn (string $runtimeUnit): bool => in_array(
                $runtimeUnit,
                $expectedRuntimeUnitsForApp,
                strict: true,
            ))
            ->filter(
                static fn (string $runtimeUnit): bool => $runtimeUnitPrefix === null
                || str_starts_with($runtimeUnit, $runtimeUnitPrefix),
            )
            ->map(function (string $runtimeUnit) use ($process, $isDocker): DriftEntry {
                $detail = $this->runtimeUnitDetail->for($process, [
                    'name' => $runtimeUnit,
                    'config_path' => $runtimeUnit,
                ]);

                if (! $isDocker) {
                    $runtime = $this->runtimeContextResolver->runtime($process);
                    $detail['expected_path'] = $runtime === ProcessRuntime::Launchd
                        ? $this->launchdExtraExpectedPath($process, $runtimeUnit)
                        : "/etc/systemd/system/{$runtimeUnit}.service";
                }

                return new DriftEntry(
                    family: 'process',
                    key: 'process.runtime_unit_extra',
                    kind: DriftKind::Extra,
                    summary: "Process runtime unit {$runtimeUnit} has no matching active gateway process intent.",
                    detail: $detail,
                );
            })
            ->values()
            ->all();

        return array_values($drift);
    }

    /** @return list<string> */
    private function expectedRuntimeUnitsForApp(Process $process): array
    {
        $app = $process->ownerApp();
        $node = $this->runtimeContextResolver->executionNode($process);

        if (! $app instanceof App || ! $node instanceof Node) {
            return [];
        }

        $runtimeUnits = [];
        $query = Process::query()
            ->with(['owner', 'instance'])
            ->where('instance_id', $process->instance_id);

        if ($this->runtimeContextResolver->productionNodeExcludesWorkspaces($node)) {
            $query->whereNotIn('owner_type', self::WORKSPACE_OWNER_TYPES);
        }

        $query->each(function (Process $candidate) use ($app, $node, &$runtimeUnits): void {
            $candidateApp = $candidate->ownerApp();

            if (! $candidateApp instanceof App || ! $candidateApp->is($app)) {
                return;
            }

            $candidateNode = $this->runtimeContextResolver->executionNode($candidate);

            if (! $candidateNode instanceof Node || ! $candidateNode->is($node)) {
                return;
            }

            try {
                $runtimeUnits = [...$runtimeUnits, ...$this->expectedRuntimeUnits->names($candidate)];
            } catch (InvalidArgumentException) {
                return;
            }
        });

        return array_values(array_unique($runtimeUnits));
    }

    private function runtimeUnitPrefix(Process $process): ?string
    {
        $app = $process->ownerApp();

        if (! $app instanceof App || $app->name === '') {
            return null;
        }

        $process->loadMissing('instance');

        return $process->instance instanceof Instance
            ? "orbit_{$app->name}_{$process->instance->name}_"
            : "orbit_{$app->name}_";
    }

    private function launchdExtraExpectedPath(Process $process, string $runtimeUnit): string
    {
        $node = $this->runtimeContextResolver->executionNode($process);

        if (! $node instanceof Node) {
            return "dev.hardimpact.orbit.{$runtimeUnit}.plist";
        }

        try {
            return $this->expectedRuntimeUnits->launchdPath($runtimeUnit, $node);
        } catch (InvalidArgumentException) {
            return "dev.hardimpact.orbit.{$runtimeUnit}.plist";
        }
    }
}
