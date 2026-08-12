<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessesProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private ProcessRuntimeObserverRegistry $runtimeObservers,
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private NodeRoleAssignments $nodeRoles,
        private NodeWireGuardSelfRouteProbe $wireGuardSelfRouteProbe,
        private ProcessRuntimeContextDiff $runtimeContextDiff,
        private ProcessRuntimeUnitDiff $runtimeUnitDiff,
        private ProcessOwnershipDetail $ownershipDetail,
    ) {}

    public function key(): string
    {
        return 'process';
    }

    public function label(): string
    {
        return 'Processes';
    }

    public function introspect(Process $process): ProbeSnapshot
    {
        return $this->runtimeObservers->observe($process);
    }

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($process));
        $drift = array_merge($drift, $this->checkOwner($process));
        $drift = array_merge($drift, $this->runtimeContextDiff->diff($process));
        $drift = array_merge($drift, $this->checkWireGuardSelfRoute($process));

        return array_merge($drift, $this->runtimeUnitDiff->diff($process, $snapshot));
    }

    /**
     * @return list<DriftEntry>
     *
     * @mago-expect analyzer:mixed-assignment
     * @mago-expect analyzer:redundant-logical-operation
     * @mago-expect analyzer:redundant-type-comparison
     */
    private function checkRecordCompleteness(Process $process): array
    {
        $process->loadMissing('owner');
        $restartPolicy = $process->getRawOriginal('restart_policy');
        $crashNotification = $process->getRawOriginal('crash_notification');
        $requiresInstance = $process->owner instanceof App || $process->owner instanceof Workspace;

        if (
            ! is_int($process->node_id)
            || ! is_string($process->owner_type)
            || $process->owner_type === ''
            || ! is_int($process->owner_id)
            || $requiresInstance
            && ! is_int($process->instance_id)
            || ! is_string($process->name)
            || $process->name === ''
            || ! is_string($process->command)
            || trim($process->command) === ''
            || ! is_int($process->sort_order)
            || ! is_string($restartPolicy)
            || ProcessRestartPolicy::tryFrom($restartPolicy) === null
            || ! is_string($crashNotification)
            || ProcessCrashNotification::tryFrom($crashNotification) === null
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Process record for {$process->name} is missing required fields.",
                    detail: $this->ownershipDetail->for($process),
                ),
            ];
        }

        return [];
    }

    /** @return list<DriftEntry> */
    private function checkOwner(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            if ($process->owner->isActive()) {
                return [];
            }

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} owner node {$process->owner->name} is not active.",
                ),
            ];
        }

        $this->runtimeContextResolver->loadRuntimeApp($process);
        $app = $process->app;

        if (! $app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} points at a missing app.",
                ),
            ];
        }

        $node = $this->runtimeContextResolver->executionNode($process);
        $instance = $process->instance;

        if (
            ! $instance instanceof Instance
            || $instance->app_id !== $app->id
            || ! $node instanceof Node
            || ! $node->isActive()
            || ! $this->nodeRoles->nodeHasActiveAppHostRole($node)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'process.owner_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Process {$process->name} owner instance is not on an active app node.",
                    detail: $this->ownershipDetail->for($process),
                ),
            ];
        }

        return [];
    }

    /** @return list<DriftEntry> */
    private function checkWireGuardSelfRoute(Process $process): array
    {
        $node = $this->runtimeContextResolver->executionNode($process);

        if (! $node instanceof Node) {
            return [];
        }

        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            return [];
        }

        $endpoint = collect($this->serviceEndpoints($process))
            ->first(static fn (array $endpoint): bool => $endpoint['host'] === $wireGuardAddress);

        if (! is_array($endpoint)) {
            return [];
        }

        $diagnostic = $this->wireGuardSelfRouteProbe->probe($node);

        if ($diagnostic['ok'] === true) {
            return [];
        }

        if ($diagnostic['supported'] === false) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'process.wireguard_self_route_unavailable',
                kind: DriftKind::Unverifiable,
                summary: "Process {$process->name} endpoint points at node {$node->name}'s own WireGuard address, but local self-route diagnostics are not healthy.",
                detail: [
                    'process' => $process->name,
                    'node' => $node->name,
                    'endpoint' => $endpoint['name'],
                    'host' => $endpoint['host'],
                    'port' => $endpoint['port'],
                    ...$this->wireGuardSelfRouteDetail($diagnostic),
                ],
            ),
        ];
    }

    /** @return list<array{name: string|null, host: string, port: int|null}> */
    private function serviceEndpoints(Process $process): array
    {
        $config = $process->runtime_config;
        $rawEndpoints = [];

        if (is_array($config['endpoint'] ?? null)) {
            $rawEndpoints[] = $config['endpoint'];
        }

        if (is_array($config['endpoints'] ?? null)) {
            /** @mago-expect analyzer:mixed-assignment */
            foreach ($config['endpoints'] as $endpoint) {
                if (! is_array($endpoint)) {
                    continue;
                }

                $rawEndpoints[] = $endpoint;
            }
        }

        $endpoints = [];

        foreach ($rawEndpoints as $endpoint) {
            $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

            if ($host === '') {
                continue;
            }

            $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;
            $port = is_numeric($endpoint['port'] ?? null) ? (int) $endpoint['port'] : null;

            $endpoints[] = [
                'name' => $name !== '' ? $name : null,
                'host' => $host,
                'port' => $port,
            ];
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function wireGuardSelfRouteDetail(array $diagnostic): array
    {
        return array_filter(
            [
                'wireguard_address' => $diagnostic['wireguard_address'] ?? null,
                'platform' => $diagnostic['platform'] ?? null,
                'supported' => $diagnostic['supported'] ?? null,
                'reason' => $diagnostic['reason'] ?? null,
                'message' => $diagnostic['message'] ?? null,
                'command' => $diagnostic['command'] ?? null,
                'exit_code' => $diagnostic['exit_code'] ?? null,
                'output' => $diagnostic['output'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
