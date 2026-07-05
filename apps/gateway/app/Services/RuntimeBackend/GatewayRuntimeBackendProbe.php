<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RuntimeBackend\GatewayRuntimeBackendProbeResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\Runtime\OrbitContainerNames;

/**
 * Gateway-only runtime backend probe.
 *
 * Inspects the gateway `orbit-gateway` container state over node transport. This probe
 * is scoped to gateway nodes only; app-host nodes use {@see RuntimeBackendProbe}
 * for the host process runtime.
 */
final readonly class GatewayRuntimeBackendProbe
{
    public function __construct(
        private ?RemoteLocalExecutor $localExecutor = null,
        private OrbitContainerNames $containerNames = new OrbitContainerNames,
    ) {}

    public function check(Node $node): GatewayRuntimeBackendProbeResult
    {
        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:gateway-runtime-backend:probe',
            arguments: [
                $this->containerNames->gateway(),
            ],
            transportOptions: [
                'timeout' => 15,
                'throw' => false,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'gateway-runtime-backend.probe',
                ],
            ],
        );
        $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        $runtimeStatus = is_string($data['runtime_status'] ?? null) ? $data['runtime_status'] : 'unknown';

        return new GatewayRuntimeBackendProbeResult(
            runtimeStatus: $runtimeStatus,
            containerExists: ($data['container_exists'] ?? null) === true,
            containerRunning: ($data['container_running'] ?? null) === true,
            exitCode: $result->exitCode,
            output: trim($result->output()),
        );
    }

    public function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    /**
     * Compare the observed orbit-gateway container state against the
     * expectation that it must exist and be running.
     *
     * @return list<DriftEntry>
     */
    public function diff(Node $node, ProbeSnapshot $snapshot): array
    {
        $runtimeName = $this->containerNames->gateway();
        $observed = $snapshot->get($runtimeName);

        if (! is_array($observed)) {
            return [];
        }

        $runtimeStatus = is_string($observed['runtime_status'] ?? null)
            ? $observed['runtime_status']
            : 'available';

        if ($runtimeStatus !== 'available') {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.docker_runtime_unavailable',
                    kind: DriftKind::Divergent,
                    summary: $runtimeStatus === 'no_docker'
                        ? "Docker CLI is not installed on {$node->name}; orbit-gateway cannot be probed."
                        : "Docker daemon is unreachable on {$node->name}; orbit-gateway cannot be probed.",
                    detail: [
                        'container' => $runtimeName,
                        'node' => $node->name,
                        'runtime_status' => $runtimeStatus,
                    ],
                ),
            ];
        }

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.runtime_container_missing',
                    kind: DriftKind::Missing,
                    summary: "Gateway container {$runtimeName} is missing on {$node->name}.",
                    detail: [
                        'container' => $runtimeName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        if (($observed['container_running'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: 'node',
                    key: 'node.runtime_container_stopped',
                    kind: DriftKind::Divergent,
                    summary: "Gateway container {$runtimeName} is not running on {$node->name}.",
                    detail: [
                        'container' => $runtimeName,
                        'node' => $node->name,
                    ],
                ),
            ];
        }

        return [];
    }
}
