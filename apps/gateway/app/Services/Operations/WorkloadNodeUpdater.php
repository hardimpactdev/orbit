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
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\ProvisioningAgentReadinessProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use Orbit\Core\Updates\AgentAvailabilityError;
use RuntimeException;
use Throwable;

/** @mago-expect lint:kan-defect */
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
        private NodeAgentServicePayloadBuilder $agentServices,
        private PhpRuntimeCatalog $phpRuntimes,
        private ProvisioningAgentReadinessProbe $agentReadiness,
        private FleetUpdatePreMutationSkipRegistry $preMutationSkips,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): array
    {
        $results = [];

        foreach ($this->targets->workloadNodes($operationRun) as $node) {
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

        $preMutationSkip = $this->preMutationSkip($node);

        if ($preMutationSkip !== null) {
            $this->recordSkippedResult($operationRun, $node, $preMutationSkip);

            return $preMutationSkip;
        }

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

        /** @var array<string, mixed> $result */
        $status = $result['status'] ?? null;

        if ($status === 'skipped') {
            $this->recordSkippedResult($operationRun, $node, $result);

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
        $nodeHome = $this->hostPaths->homeDirectory($node);
        $commandOptions = [];

        /** @var array{timeout: int, cwd: string, input: string, environment: array<string, string>, metadata: array<string, string>, bind_application_key: false, bind_input: true} $transportOptions */
        $transportOptions = [
            'timeout' => 300,
            'cwd' => $nodeHome,
            'input' => $installPayload,
            'environment' => [
                'HOME' => $nodeHome,
                'ORBIT_CONFIG_PATH' => "{$nodeHome}/.config/orbit/config.json",
                'ORBIT_INSTALL_METADATA_PATH' => "{$nodeHome}/.config/orbit/install.json",
            ],
            'metadata' => $this->remoteShellMetadata($operationRun, $node),
            'bind_application_key' => false,
            'bind_input' => true,
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
     * @return array<string, mixed>|null
     */
    private function preMutationSkip(Node $node): ?array
    {
        if ($this->agentReadiness->isReady($node)) {
            return null;
        }

        $reason = NodeHostPaths::isMacosPlatform($node->platform)
            ? AgentAvailabilityError::DesktopNotRunning
            : AgentAvailabilityError::AgentNotRunning;

        return [
            ...$this->targetPayload($node),
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordSkippedResult(OperationRun $operationRun, Node $node, array $result): void
    {
        $reason = is_string($result['reason'] ?? null) ? $result['reason'] : null;

        if (
            $reason === AgentAvailabilityError::DesktopNotRunning
            || $reason === AgentAvailabilityError::AgentNotRunning
        ) {
            $this->preMutationSkips->record($operationRun->id, $node->name, $reason);
        }

        $message = match ($reason) {
            AgentAvailabilityError::DesktopNotRunning
                => "Workload node {$node->name} skipped: Orbit Desktop is not running",
            AgentAvailabilityError::AgentNotRunning
                => "Workload node {$node->name} skipped: Orbit Agent is not running",
            default => "Workload node {$node->name} skipped: already up to date",
        };

        $this->operationRuns->appendStep(
            $operationRun->id,
            $this->eventKey($node),
            'done',
            $message,
        );
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
     *     agent_service: array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}|null,
     *     desktop_artifact: array{artifact_url: string, sha256: string, signature: string, version: string, platform: string, architecture: string, staged_path: string}|null,
     *     pending_desktop_update: array{path: string, operation_id: string, version: string, build_id: string|null, install_mode: string}|null,
     *     role_images: list<string>,
     *     role_image_artifacts: list<array{image: string, url: string, sha256: string}>,
     *     role_image_aliases: list<array{source: string, target: string}>,
     * }
     */
    private function installPayload(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $node,
        array $artifact,
    ): array {
        $installRoot = rtrim($node->orbit_path, '/') ?: '/home/orbit/orbit';
        $desktopArtifact = $this->desktopArtifactPayload($operationRun, $plan, $node);

        return [
            'artifact_url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
            'install_root' => $installRoot,
            'bin_path' => FleetUpdateNodeCliLauncher::binPath($node),
            'shared_binary_path' => null,
            'agent_artifact' => $this->agentArtifactPayload($operationRun, $plan, $node),
            'agent_service' => $this->agentServicePayload($node),
            'desktop_artifact' => $desktopArtifact,
            'pending_desktop_update' => $this->pendingDesktopUpdatePayload(
                $operationRun,
                $plan,
                $node,
                $desktopArtifact,
            ),
            'role_images' => $this->requiredRoleImages($plan, $node),
            'role_image_artifacts' => $this->requiredRoleImageArtifacts($plan, $node),
            'role_image_aliases' => $this->requiredRoleImageAliases($plan, $node),
        ];
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
        if (! $this->shouldInstallAgentArtifact($node)) {
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

        $metadata['ORBIT_BIN_PATH'] = FleetUpdateNodeCliLauncher::binPath($node);

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
        if (! $this->shouldInstallAgentArtifact($node)) {
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
     * @return array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}|null
     */
    private function agentServicePayload(Node $node): ?array
    {
        if (! $this->shouldInstallAgentArtifact($node)) {
            return null;
        }

        $gateway = $this->targets->gatewayNode();

        if (! $gateway instanceof Node) {
            throw new RuntimeException('An active gateway identity is required to configure Orbit Agent.');
        }

        return $this->agentServices->forFleetUpdateNode($node, $gateway);
    }

    private function shouldInstallAgentArtifact(Node $node): bool
    {
        return $node->isFleetUpdateEligible();
    }

    /**
     * @return array{artifact_url: string, sha256: string, signature: string, version: string, platform: string, architecture: string, staged_path: string}|null
     */
    private function desktopArtifactPayload(OperationRun $operationRun, OperationUpdatePlan $plan, Node $node): ?array
    {
        if (! NodeHostPaths::isMacosPlatform($node->platform)) {
            return null;
        }

        if ($this->agentArtifactPayload($operationRun, $plan, $node) === null) {
            return null;
        }

        $artifact = $this->artifactRelay->desktopArtifactFor(
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
            'signature' => $artifact['signature'],
            'version' => $artifact['version'],
            'platform' => $artifact['platform'],
            'architecture' => $artifact['architecture'],
            'staged_path' =>
                $this->hostPaths->homeDirectory($node)
                    .'/.local/share/orbit/updates/desktop-'
                    .$this->shaPrefix($artifact['sha256'])
                    .'.tar.gz',
        ];
    }

    /**
     * @param  array{artifact_url: string, sha256: string, signature: string, version: string, platform: string, architecture: string, staged_path: string}|null  $desktopArtifact
     * @return array{path: string, operation_id: string, version: string, build_id: string|null, install_mode: string}|null
     */
    private function pendingDesktopUpdatePayload(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $node,
        ?array $desktopArtifact,
    ): ?array {
        if ($desktopArtifact === null) {
            return null;
        }

        return [
            'path' => $this->hostPaths->userConfigRoot($node).'/pending-desktop-update.json',
            'operation_id' => $operationRun->id,
            'version' => $plan->target_version,
            'build_id' => $this->manifestBuildId($plan),
            'install_mode' => 'restart-ready',
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

        foreach ($this->requiredRoleImageKeys($plan, $node) as $key) {
            $image = $plan->runtimeRoleImage($key);

            if ($image === null) {
                throw new RuntimeException("Update plan contains an invalid role image for [{$key}].");
            }

            $images[] = $image;
        }

        return array_values(array_unique($images));
    }

    /**
     * @return list<array{image: string, url: string, sha256: string}>
     */
    private function requiredRoleImageArtifacts(OperationUpdatePlan $plan, Node $node): array
    {
        $manifestArtifacts = $plan->manifest_snapshot['role_image_artifacts'] ?? null;

        if (! is_array($manifestArtifacts)) {
            return [];
        }

        $artifacts = [];

        foreach ($this->requiredRoleImageKeys($plan, $node) as $key) {
            if (! isset($manifestArtifacts[$key]) || ! is_array($manifestArtifacts[$key])) {
                continue;
            }

            $artifact = $manifestArtifacts[$key];

            if (
                ! isset($artifact['url'], $artifact['sha256'])
                || ! is_string($artifact['url'])
                || ! is_string($artifact['sha256'])
                || ! isset($plan->role_images[$key])
                || ! is_string($plan->role_images[$key])
            ) {
                throw new RuntimeException("Update plan contains an invalid role image artifact for [{$key}].");
            }

            $artifacts[] = [
                'image' => $plan->role_images[$key],
                'url' => $artifact['url'],
                'sha256' => $artifact['sha256'],
            ];
        }

        return $artifacts;
    }

    /**
     * @return list<array{source: string, target: string}>
     */
    private function requiredRoleImageAliases(OperationUpdatePlan $plan, Node $node): array
    {
        if (
            $plan->manifest_source !== 'topology-candidate'
            || ! in_array('orbit-frankenphp', $this->requiredRoleImageKeys($plan, $node), true)
        ) {
            return [];
        }

        $declaredSource = $plan->role_images['orbit-frankenphp'] ?? null;
        $source = $plan->runtimeRoleImage('orbit-frankenphp');
        $target = $this->phpRuntimes->imageFor(PhpRuntimeCatalog::DEFAULT);
        $pattern = '/\A'.preg_quote($target, '/').'-candidate-[^@\s]+@sha256:[0-9a-f]{64}\z/i';

        if (! is_string($declaredSource) || preg_match($pattern, $declaredSource) !== 1 || $source === null) {
            throw new RuntimeException('Topology candidate contains an invalid FrankenPHP role image.');
        }

        return [['source' => $source, 'target' => $target]];
    }

    /**
     * @return list<string>
     */
    private function requiredRoleImageKeys(OperationUpdatePlan $plan, Node $node): array
    {
        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return [];
        }

        $keys = [];

        if ($this->roles->nodeHostsOrbitCaddy($node) && is_string($plan->role_images['orbit-caddy'] ?? null)) {
            $keys[] = 'orbit-caddy';
        }

        if (
            ($this->roles->nodeHasActiveRole($node, NodeRoleName::AppDevelopment->value)
            || $this->roles->nodeHasActiveRole($node, NodeRoleName::AppProduction->value))
            && is_string($plan->role_images['orbit-frankenphp'] ?? null)
        ) {
            $keys[] = 'orbit-frankenphp';
        }

        if (
            $this->roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value)
            && is_string($plan->role_images['orbit-websocket'] ?? null)
        ) {
            $keys[] = 'orbit-websocket';
        }

        return $keys;
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
     * @return array{target: string, node: string, roles: list<string>}
     */
    private function targetPayload(Node $node): array
    {
        return [
            'target' => $node->name,
            'node' => $node->name,
            'roles' => $this->roles->activeRoleNames($node),
        ];
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
