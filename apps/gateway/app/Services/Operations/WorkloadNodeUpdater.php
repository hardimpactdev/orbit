<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Nodes\InstalledAgentArtifact;
use App\Data\Nodes\InstalledCliArtifact;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use RuntimeException;
use Throwable;

final readonly class WorkloadNodeUpdater
{
    public function __construct(
        private NodeRoleAssignments $roles,
        private NodeHostPaths $hostPaths,
        private UpdateLeaseManager $leases,
        private OperationRunRecorder $operationRuns,
        private FleetUpdateTargetSelector $targets,
        private FleetVersionProbe $fleetVersions,
        private FleetUpdateNodeInstaller $nodeInstaller,
        private GatewayCliArtifactRelay $artifactRelay,
        private RemoteNodeDoctor $nodeDoctor,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): array
    {
        $results = [];

        foreach ($this->targets->workloadNodes() as $node) {
            $results[] = $this->updateNode($operationRun, $plan, $node);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function updateNode(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            "Updating workload node {$node->name}",
        );

        try {
            $result = $this->leases->withLease(
                resourceType: 'node',
                resourceKey: $node->name,
                operationRun: $operationRun,
                ownerToken: $this->ownerToken($operationRun, $node),
                ttlSeconds: $this->leaseTtlSeconds(),
                callback: fn (): array => $this->runRemoteUpdate($operationRun, $plan, $node),
            );
        } catch (UpdateLeaseConflict $exception) {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'fail',
                $exception->getMessage(),
            );

            throw $exception;
        } catch (Throwable $exception) {
            $result = [
                ...$this->targetPayload($node),
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => $exception->getMessage(),
            ];
        }

        $status = $result['status'] ?? null;

        if ($status === 'skipped') {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'done',
                "Workload node {$node->name} skipped: already up to date",
            );

            return $result;
        }

        if ($status === 'completed') {
            $this->operationRuns->appendStep(
                $operationRun->id,
                $this->eventKey($node),
                'done',
                $this->updatedMessage($node, $result['doctor_issues'] ?? null),
            );

            return $result;
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'fail',
            is_string($result['output'] ?? null) ? $result['output'] : "Workload node {$node->name} update failed",
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function runRemoteUpdate(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        if (! $this->fleetVersions->nodeNeedsUpdate($node, $plan)) {
            return [
                ...$this->targetPayload($node),
                'status' => 'skipped',
            ];
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            "Installing CLI {$plan->target_version}",
        );

        $cliArtifact = $this->cliArtifact($operationRun, $plan, $node);
        $installPayload = json_encode(
            $this->installPayload($operationRun, $plan, $node, $cliArtifact),
            JSON_THROW_ON_ERROR,
        );
        $payloadSha256 = hash('sha256', $installPayload);
        $payloadPath = $this->installPayloadPath($operationRun, $node);
        $nodeHome = $this->hostPaths->homeDirectory($node);
        $commandOptions = [
            'payload-file' => $payloadPath,
            'payload-sha256' => $payloadSha256,
        ];

        /** @var array{timeout: int, cwd: string, input: string, environment: array<string, string>, metadata: array<string, string>, transport: NodeTransportPreference, bind_application_key: false, bind_input: false, ssh_bootstrap_binary: array{url: string, sha256: string}, ssh_bootstrap_input_file: array{path: string, sha256: string}} $transportOptions */
        $transportOptions = [
            'timeout' => 300,
            'cwd' => $nodeHome,
            'input' => $installPayload,
            'environment' => [
                'HOME' => $nodeHome,
                'ORBIT_CONFIG_PATH' => "{$nodeHome}/.config/orbit/config.json",
            ],
            'metadata' => $this->remoteShellMetadata($operationRun, $node),
            'transport' => NodeTransportPreference::TransitionalSshFallback,
            'bind_application_key' => false,
            'bind_input' => false,
            'ssh_bootstrap_binary' => [
                'url' => $cliArtifact['url'],
                'sha256' => $cliArtifact['sha256'],
            ],
            'ssh_bootstrap_input_file' => [
                'path' => $payloadPath,
                'sha256' => $payloadSha256,
            ],
        ];

        $result = $this->nodeInstaller->run(
            operationRun: $operationRun,
            node: $node,
            eventKey: $this->eventKey($node),
            commandOptions: $commandOptions,
            transportOptions: $transportOptions,
        );

        if ($result instanceof RemoteShellResult && ! $result->successful()) {
            return [
                ...$this->targetPayload($node),
                'status' => 'failed',
                'failed_step' => 'remote_update',
                'output' => $this->output($result),
            ];
        }

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            'Recording installed CLI',
        );

        $this->recordInstalledCli($operationRun, $plan, $node);

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'running',
            'Running doctor',
        );

        return [
            ...$this->targetPayload($node),
            'status' => 'completed',
            'doctor_issues' => $this->runNodeDoctor($operationRun, $node),
        ];
    }

    /**
     * Run streaming `orbit doctor` in verify mode for the node as the final per-node step.
     * The verify is non-fatal: a non-zero issue count is surfaced per node but
     * does not by itself fail the node's update, and any failure to resolve the
     * count yields `null` (unknown).
     */
    private function runNodeDoctor(OperationRun $operationRun, Node $node): ?int
    {
        return $this->nodeDoctor->issues($node, $operationRun);
    }

    private function updatedMessage(Node $node, ?int $issues): string
    {
        if ($issues === null || $issues === 0) {
            return "Workload node {$node->name} updated";
        }

        $noun = $issues === 1 ? 'issue' : 'issues';

        return "Workload node {$node->name} updated ({$issues} {$noun})";
    }

    /**
     * @param  array{url: string, sha256: string, source_url: string}  $artifact
     * @return array{
     *     artifact_url: string,
     *     sha256: string,
     *     install_root: string,
     *     bin_path: string,
     *     shared_binary_path: string|null,
     *     agent_artifact: array{artifact_url: string, sha256: string, bin_path: string}|null,
     *     role_images: list<string>,
     * }
     */
    private function installPayload(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $node,
        array $artifact,
    ): array {
        $installRoot = rtrim($node->orbit_path, '/') ?: '/home/orbit/orbit';

        return [
            'artifact_url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
            'install_root' => $installRoot,
            'bin_path' => NodeHostPaths::isMacosPlatform($node->platform)
                ? $this->hostPaths->homeDirectory($node).'/.local/bin/orbit'
                : '/usr/local/bin/orbit',
            'shared_binary_path' => null,
            'agent_artifact' => $this->agentArtifactPayload($operationRun, $plan, $node),
            'role_images' => $this->requiredRoleImages($plan, $node),
        ];
    }

    private function installPayloadPath(OperationRun $operationRun, Node $node): string
    {
        $safeNodeName = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $node->name) ?? 'node';

        return "/tmp/orbit-fleet-update-install-{$operationRun->id}-{$safeNodeName}.json";
    }

    private function recordInstalledCli(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): void
    {
        $platform = CliArtifactPlatform::forNode($node);
        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
        ) {
            throw new RuntimeException("Update plan does not contain a CLI artifact for platform [{$platform}].");
        }

        $installRoot = rtrim($node->orbit_path, '/') ?: '/home/orbit/orbit';

        $node->forceFill([
            'installed_cli' => InstalledCliArtifact::record(
                version: $plan->target_version,
                platform: $platform,
                sha256: $artifact['sha256'],
                source: $plan->manifest_source,
                buildId: $this->manifestBuildId($plan),
                artifactUrl: $artifact['url'],
                installedPath: "{$installRoot}/bin/orbit-binary-{$this->shaPrefix($artifact['sha256'])}",
                operationRunId: $operationRun->id,
            ),
        ])->save();

        $this->recordInstalledAgent($operationRun, $plan, $node);
    }

    private function recordInstalledAgent(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): void
    {
        if (! $node->orbit_agent_capable) {
            return;
        }

        $platform = CliArtifactPlatform::forNode($node);
        $artifact = $plan->agent_artifacts[$platform] ?? null;

        if ($artifact === null) {
            return;
        }

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
        ) {
            throw new RuntimeException("Update plan contains an invalid agent artifact for platform [{$platform}].");
        }

        $node->forceFill([
            'installed_agent' => InstalledAgentArtifact::record([
                'version' => $plan->target_version,
                'platform' => $platform,
                'sha256' => $artifact['sha256'],
                'source' => $plan->manifest_source,
                'build_id' => $this->manifestBuildId($plan),
                'artifact_url' => $artifact['url'],
                'installed_path' => FleetUpdateNodeAgentBinary::binPath($node),
                'operation_run_id' => $operationRun->id,
            ]),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    private function remoteShellMetadata(OperationRun $operationRun, Node $node): array
    {
        $metadata = [
            'ORBIT_OPERATION_ID' => $operationRun->id,
        ];

        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            $metadata['ORBIT_BIN_PATH'] = $this->hostPaths->homeDirectory($node).'/.local/bin/orbit';
        }

        return $metadata;
    }

    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    private function cliArtifact(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): array
    {
        $platform = CliArtifactPlatform::forNode($node);

        return $this->artifactRelay->artifactFor($operationRun, $plan, $platform);
    }

    /**
     * @return array{artifact_url: string, sha256: string, bin_path: string}|null
     */
    private function agentArtifactPayload(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): ?array
    {
        if (! $node->orbit_agent_capable) {
            return null;
        }

        $artifact = $this->artifactRelay->agentArtifactFor(
            operationRun: $operationRun,
            plan: $plan,
            platform: CliArtifactPlatform::forNode($node),
        );

        if ($artifact === null) {
            return null;
        }

        return [
            'artifact_url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
            'bin_path' => FleetUpdateNodeAgentBinary::binPath($node),
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredRoleImages(OperationUpdatePlan $plan, Node $node): array
    {
        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return [];
        }

        $images = [];

        if ($this->roles->nodeHostsOrbitCaddy($node) && is_string($plan->role_images['orbit-caddy'] ?? null)) {
            $images[] = $plan->role_images['orbit-caddy'];
        }

        if (
            $this->roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value)
            && is_string($plan->role_images['orbit-websocket'] ?? null)
        ) {
            $images[] = $plan->role_images['orbit-websocket'];
        }

        return array_values(array_unique($images));
    }

    private function manifestBuildId(OperationUpdatePlan $plan): ?string
    {
        $buildId = $plan->manifest_snapshot['build_id'] ?? null;

        return is_string($buildId) && $buildId !== '' ? $buildId : null;
    }

    private function shaPrefix(string $sha256): string
    {
        return substr(strtolower($sha256), offset: 0, length: 12);
    }

    /**
     * @return array{target: string, node: string, role: string}
     */
    private function targetPayload(Node $node): array
    {
        return [
            'target' => $node->name,
            'node' => $node->name,
            'role' => $this->roleLabel($node),
        ];
    }

    private function roleLabel(Node $node): string
    {
        return $this->roles->assignmentRoleLabel($node);
    }

    private function output(RemoteShellResult $result): string
    {
        $output = trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());

        return $output !== '' ? $output : "exit code {$result->exitCode}";
    }

    private function ownerToken(OperationRun $operationRun, Node $node): string
    {
        return hash('sha256', implode(':', [
            'update-runner',
            $operationRun->id,
            'node',
            $node->name,
        ]));
    }

    private function eventKey(Node $node): string
    {
        return "workload.{$node->name}";
    }

    private function leaseTtlSeconds(): int
    {
        $ttlSeconds = (int) config('orbit.updates.lease_ttl_seconds', 300);

        return max(1, $ttlSeconds);
    }
}
