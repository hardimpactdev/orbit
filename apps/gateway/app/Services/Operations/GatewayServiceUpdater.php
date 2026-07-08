<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Nodes\InstalledAgentArtifact;
use App\Data\Nodes\InstalledCliArtifact;
use App\Data\Nodes\InstalledGatewayImage;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Gateway\GatewayExposureMode;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewayHostAgentConfigWriter;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmInstaller;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:kan-defect
 */
class GatewayServiceUpdater
{
    private const string GatewayService = 'orbit_orbit-gateway';

    private const string SchedulerService = 'orbit_orbit-scheduler';

    private const string OPERATIONS_REVERB_SERVICE = 'orbit_orbit-operations-reverb';

    private const int GatewayHealthCheckAttempts = 90;

    private const int GatewayHealthCheckSleepSeconds = 2;

    public function __construct(
        private readonly ?GatewaySwarmManager $swarm = null,
        private readonly ?OperationRunRecorder $operationRuns = null,
        private readonly ?FleetUpdateTargetSelector $targets = null,
        private readonly ?GatewayHostAgentConfigWriter $gatewayHostAgentConfigs = null,
        private readonly ?GatewayHostAgentServicePayloadBuilder $gatewayHostAgentServices = null,
    ) {}

    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $targetImage = GatewayImageReference::fromString($plan->gateway_image);
        $previousSchedulerImage = $this->swarm()->serviceImage(self::SchedulerService);
        $schedulerWasStopped = false;

        try {
            $this->runStep(
                $operationRun,
                'scheduler.stop',
                'Stopping orbit-scheduler service',
                'orbit-scheduler service stopped',
                fn (): null => $this->scaleSchedulerToZero(),
            );
            $schedulerWasStopped = true;

            $this->runStep(
                $operationRun,
                'migrations',
                'Running gateway migrations',
                'Gateway migrations completed',
                fn (): null => $this->runMigrations(),
            );
            $this->runStep(
                $operationRun,
                'gateway.service',
                'Updating orbit-gateway service',
                'orbit-gateway service healthy',
                fn (): null => $this->updateGatewayService($targetImage),
            );
            $this->runStep(
                $operationRun,
                'scheduler.start',
                'Starting orbit-scheduler service',
                'orbit-scheduler service running',
                fn (): null => $this->updateAndStartScheduler($targetImage),
            );
            $this->runStep(
                $operationRun,
                'gateway.stack',
                'Converging gateway Swarm stack',
                'Gateway Swarm stack converged',
                fn (): null => $this->convergeGatewayStack($targetImage, $plan),
            );
            $this->runStep(
                $operationRun,
                'gateway.agent-config',
                'Writing gateway host agent config',
                'Gateway host agent config written',
                $this->writeGatewayHostAgentConfig(...),
            );
            $this->runStep(
                $operationRun,
                'gateway.host-cli',
                'Installing gateway host CLI',
                'Gateway host CLI installed',
                fn (): null => $this->installGatewayHostCli($operationRun, $plan),
            );
            $this->runStep(
                $operationRun,
                'gateway.agent-service',
                'Restarting gateway host Orbit Agent service',
                'Gateway host Orbit Agent service restarted',
                fn (): null => $this->restartGatewayHostAgentService($operationRun),
            );

            $this->recordInstalledGatewayImage($operationRun, $plan, $targetImage);
        } catch (Throwable $throwable) {
            if ($schedulerWasStopped) {
                $this->recoverScheduler($operationRun, $previousSchedulerImage, $throwable);
            }

            throw $throwable;
        }
    }

    private function scaleSchedulerToZero(): null
    {
        $this->swarm()->scaleService(self::SchedulerService, 0);

        return null;
    }

    private function restartGatewayHostAgentService(OperationRun $operationRun): null
    {
        $gatewayNode = $this->targets()->gatewayNode();

        if (! $gatewayNode instanceof Node) {
            return null;
        }

        $agentService = $this->gatewayHostAgentServices()->forNode($gatewayNode);

        if ($agentService === null) {
            return null;
        }

        app(GatewayHostAgentServiceRestarter::class)->restart($operationRun, $gatewayNode, $agentService);

        return null;
    }

    private function installGatewayHostCli(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        $gatewayNode = $this->targets()->gatewayNode();

        if (! $gatewayNode instanceof Node) {
            return null;
        }

        $gatewayCliArtifact = $this->gatewayHostCliArtifact($operationRun, $plan, $gatewayNode);
        $installPayload = json_encode(
            $this->gatewayHostCliInstallPayload($operationRun, $plan, $gatewayNode, $gatewayCliArtifact),
            JSON_THROW_ON_ERROR,
        );
        $payloadSha256 = hash('sha256', $installPayload);
        $payloadPath = $this->gatewayHostCliPayloadPath($operationRun);
        $commandOptions = [
            'payload-file' => $payloadPath,
            'payload-sha256' => $payloadSha256,
        ];

        /** @var array{timeout: int, cwd: string, input: string, metadata: array<string, string>, transport: NodeTransportPreference, bind_application_key: false, bind_input: false, force_remote_host: true, ssh_bootstrap_binary: array{url: string, sha256: string}, ssh_bootstrap_input_file: array{path: string, sha256: string}} $transportOptions */
        $transportOptions = [
            'timeout' => 300,
            'cwd' => '/home/orbit',
            'input' => $installPayload,
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operationRun->id,
            ],
            'transport' => NodeTransportPreference::TransitionalSshFallback,
            'bind_application_key' => false,
            'bind_input' => false,
            'force_remote_host' => true,
            'ssh_bootstrap_binary' => [
                'url' => $gatewayCliArtifact['url'],
                'sha256' => $gatewayCliArtifact['sha256'],
            ],
            'ssh_bootstrap_input_file' => [
                'path' => $payloadPath,
                'sha256' => $payloadSha256,
            ],
        ];

        try {
            $result = $this->runCliInstall($gatewayNode, $commandOptions, $transportOptions);
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            if (! $this->isGatewayHostAgentRestartDisconnect($exception)) {
                throw $exception;
            }

            $this->recordInstalledGatewayHostCli($operationRun, $plan, $gatewayNode);

            return null;
        }

        if ($this->shouldRetryCliInstallAfterSelfUpdate($result)) {
            $result = $this->runCliInstall($gatewayNode, $commandOptions, $transportOptions);
        }

        if (! $result->successful()) {
            throw new RuntimeException('Gateway host CLI install failed: '.$this->output($result));
        }

        $this->recordInstalledGatewayHostCli($operationRun, $plan, $gatewayNode);

        return null;
    }

    /**
     * @param  array{payload-file: string, payload-sha256: string}  $commandOptions
     * @param  array{timeout: int, cwd: string, input: string, metadata: array<string, string>, transport: NodeTransportPreference, bind_application_key: false, bind_input: false, force_remote_host: true, ssh_bootstrap_binary: array{url: string, sha256: string}, ssh_bootstrap_input_file: array{path: string, sha256: string}}  $transportOptions
     */
    private function runCliInstall(Node $gatewayNode, array $commandOptions, array $transportOptions): RemoteShellResult
    {
        return $this->localExecutor()->runInternal(
            node: $gatewayNode,
            commandName: 'internal:fleet-update:install-cli',
            arguments: [],
            commandOptions: $commandOptions,
            transportOptions: $transportOptions,
        );
    }

    private function isGatewayHostAgentRestartDisconnect(RemoteLocalExecutorTransportFailed $exception): bool
    {
        return str_contains($exception->getMessage(), 'Empty reply from server');
    }

    private function shouldRetryCliInstallAfterSelfUpdate(RemoteShellResult $result): bool
    {
        return $result->exitCode === 255 && trim($result->stdout) === '' && trim($result->stderr) === '';
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
     *     agent_service: array{unit_name: string, exec_start: string, config_path: string, http_bind: string, user: string}|null,
     *     role_images: list<string>,
     * }
     */
    private function gatewayHostCliInstallPayload(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $gatewayNode,
        array $artifact,
    ): array {
        $installRoot = $this->gatewayInstallRoot($gatewayNode);

        return [
            'artifact_url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
            'install_root' => $installRoot,
            'bin_path' => FleetUpdateNodeCliLauncher::binPath($gatewayNode),
            'shared_binary_path' => null,
            'agent_artifact' => $this->gatewayHostAgentArtifactPayload($operationRun, $plan, $gatewayNode),
            'agent_service' => $this->gatewayHostAgentServices()->forNode($gatewayNode),
            'role_images' => [],
        ];
    }

    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    private function gatewayHostCliArtifact(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $gatewayNode,
    ): array {
        return $this->artifactRelay()->artifactFor(
            operationRun: $operationRun,
            plan: $plan,
            platform: CliArtifactPlatform::forNode($gatewayNode),
        );
    }

    private function gatewayInstallRoot(Node $gatewayNode): string
    {
        $installRoot = rtrim($gatewayNode->orbit_path, characters: '/');

        return $installRoot !== '' ? $installRoot : '/home/orbit/orbit';
    }

    private function gatewayInstallRootForStack(): string
    {
        $gatewayNode = $this->targets()->gatewayNode();

        return $gatewayNode instanceof Node ? $this->gatewayInstallRoot($gatewayNode) : '/home/orbit/orbit';
    }

    private function configRoot(): string
    {
        $configRoot = config('orbit.paths.config_root', default: '/home/orbit/.config/orbit');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            return '/home/orbit/.config/orbit';
        }

        return rtrim($configRoot, characters: '/');
    }

    private function gatewayExposureMode(): GatewayExposureMode
    {
        $value = config('orbit.gateway.exposure_mode', GatewayExposureMode::RouterColocated->value);

        return GatewayExposureMode::from(is_string($value) ? $value : GatewayExposureMode::RouterColocated->value);
    }

    private function operationsReverbImage(OperationUpdatePlan $plan): string
    {
        $image = $plan->role_images['orbit-websocket'] ?? null;

        return is_string($image) && trim($image) !== ''
            ? trim($image)
            : GatewaySwarmStackRenderer::DEFAULT_OPERATIONS_REVERB_IMAGE;
    }

    private function gatewayHostCliPayloadPath(OperationRun $operationRun): string
    {
        return '/tmp/orbit-gateway-host-cli-install-'.$operationRun->id.'.json';
    }

    private function recordInstalledGatewayHostCli(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $gatewayNode,
    ): void {
        $platform = CliArtifactPlatform::forNode($gatewayNode);
        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
        ) {
            throw new RuntimeException("Update plan does not contain a CLI artifact for platform [{$platform}].");
        }

        $installRoot = $this->gatewayInstallRoot($gatewayNode);

        $gatewayNode->forceFill([
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

        $this->recordInstalledGatewayHostAgent($operationRun, $plan, $gatewayNode);
    }

    private function recordInstalledGatewayHostAgent(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $gatewayNode,
    ): void {
        $platform = CliArtifactPlatform::forNode($gatewayNode);
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

        $gatewayNode->forceFill([
            'installed_agent' => InstalledAgentArtifact::record([
                'version' => $plan->target_version,
                'platform' => $platform,
                'sha256' => $artifact['sha256'],
                'source' => $plan->manifest_source,
                'build_id' => $this->manifestBuildId($plan),
                'artifact_url' => $artifact['url'],
                'installed_path' => FleetUpdateNodeAgentBinary::binPath($gatewayNode),
                'operation_run_id' => $operationRun->id,
            ]),
        ])->save();
    }

    private function runMigrations(): null
    {
        $exitCode = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Gateway migrations failed with exit code {$exitCode}.");
        }

        return null;
    }

    /**
     * @return array{artifact_url: string, sha256: string, bin_path: string}|null
     */
    private function gatewayHostAgentArtifactPayload(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        Node $gatewayNode,
    ): ?array {
        $artifact = $this->artifactRelay()->agentArtifactFor(
            operationRun: $operationRun,
            plan: $plan,
            platform: CliArtifactPlatform::forNode($gatewayNode),
        );

        if ($artifact === null) {
            return null;
        }

        return [
            'artifact_url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
            'bin_path' => FleetUpdateNodeAgentBinary::binPath($gatewayNode),
        ];
    }

    private function updateGatewayService(GatewayImageReference $targetImage): null
    {
        $this->swarm()->updateServiceImage(self::GatewayService, $targetImage, 'start-first');
        $this->waitForGatewayHealth($targetImage);

        return null;
    }

    private function updateAndStartScheduler(GatewayImageReference $targetImage): null
    {
        $this->swarm()->updateServiceImage(self::SchedulerService, $targetImage, 'stop-first');
        $this->swarm()->scaleService(self::SchedulerService, 1);

        return null;
    }

    private function convergeGatewayStack(GatewayImageReference $targetImage, OperationUpdatePlan $plan): null
    {
        $this->swarmInstaller()->bootstrapRuntimeConfig();

        $stackPath = $this->swarm()->writeStackFile(
            $this->stackRenderer()->render(
                image: $targetImage,
                exposureMode: $this->gatewayExposureMode(),
                configRoot: $this->configRoot(),
                installRoot: $this->gatewayInstallRootForStack(),
                operationsReverbImage: $this->operationsReverbImage($plan),
            ),
        );

        $this->swarm()->deployStack($stackPath);
        $this->waitForGatewayHealth($targetImage);
        $this->waitForServiceReplica(self::SchedulerService);
        $this->waitForServiceReplica(self::OPERATIONS_REVERB_SERVICE);

        return null;
    }

    private function waitForGatewayHealth(GatewayImageReference $targetImage): void
    {
        for ($attempt = 1; $attempt <= self::GatewayHealthCheckAttempts; $attempt++) {
            $state = $this->swarm()->serviceUpdateState(self::GatewayService);

            if ($state === 'completed') {
                return;
            }

            if ($state === null && $this->gatewayServiceIsConverged($targetImage)) {
                return;
            }

            if ($state !== null && $state !== 'updating') {
                throw new RuntimeException('Gateway service health check failed.');
            }

            if ($attempt < self::GatewayHealthCheckAttempts) {
                Sleep::for(self::GatewayHealthCheckSleepSeconds)->seconds();
            }
        }

        throw new RuntimeException('Gateway service health check failed.');
    }

    private function waitForServiceReplica(string $service): void
    {
        for ($attempt = 1; $attempt <= self::GatewayHealthCheckAttempts; $attempt++) {
            if ($this->swarm()->serviceReplicas($service) === '1/1') {
                return;
            }

            if ($attempt < self::GatewayHealthCheckAttempts) {
                Sleep::for(self::GatewayHealthCheckSleepSeconds)->seconds();
            }
        }

        throw new RuntimeException("Gateway Swarm service [{$service}] did not converge.");
    }

    private function gatewayServiceIsConverged(GatewayImageReference $targetImage): bool
    {
        if ($this->swarm()->serviceImage(self::GatewayService) !== $targetImage->canonical()) {
            return false;
        }

        return $this->swarm()->serviceReplicas(self::GatewayService) === '1/1';
    }

    private function recoverScheduler(
        OperationRun $operationRun,
        ?string $previousSchedulerImage,
        Throwable $original,
    ): void {
        $this->operationRuns()->appendStep(
            $operationRun->id,
            'scheduler.recovery',
            'running',
            'Restoring orbit-scheduler service',
        );

        try {
            if ($previousSchedulerImage === null) {
                throw new RuntimeException('Previous scheduler image could not be inspected.');
            }

            $this->swarm()->updateServiceImage(
                self::SchedulerService,
                GatewayImageReference::fromString($previousSchedulerImage),
                'stop-first',
            );
            $this->swarm()->scaleService(self::SchedulerService, 1);
            $this->operationRuns()->appendStep(
                $operationRun->id,
                'scheduler.recovery',
                'done',
                'orbit-scheduler service restored',
            );
        } catch (Throwable $recovery) {
            $this->operationRuns()->appendStep(
                $operationRun->id,
                'scheduler.recovery',
                'fail',
                $recovery->getMessage(),
            );
            $this->recordSchedulerRecoveryFailed($operationRun, $previousSchedulerImage, $original, $recovery);
        }
    }

    private function runStep(
        OperationRun $operationRun,
        string $key,
        string $runningMessage,
        string $doneMessage,
        callable $callback,
    ): null {
        $this->operationRuns()->appendStep($operationRun->id, $key, 'running', $runningMessage);

        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->operationRuns()->appendStep($operationRun->id, $key, 'fail', $this->failureMessage($throwable));

            throw $throwable;
        }

        $this->operationRuns()->appendStep($operationRun->id, $key, 'done', $doneMessage);

        return null;
    }

    private function failureMessage(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return $message !== '' ? $message : 'Gateway service update failed.';
    }

    private function recordSchedulerRecoveryFailed(
        OperationRun $operationRun,
        ?string $previousSchedulerImage,
        Throwable $original,
        Throwable $recovery,
    ): void {
        $this->operationRuns()->appendError($operationRun->id, 'Scheduler recovery failed.', 1, [
            'code' => 'update.scheduler_recovery_failed',
            'recovery_command' => $this->schedulerRecoveryCommand($previousSchedulerImage),
            'original_failure' => $original->getMessage(),
            'recovery_failure' => $recovery->getMessage(),
        ]);
    }

    private function recordInstalledGatewayImage(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        GatewayImageReference $targetImage,
    ): void {
        $gatewayNode = $this->targets()->gatewayNode();

        if ($gatewayNode === null) {
            return;
        }

        $gatewayNode->forceFill([
            'installed_gateway_image' => InstalledGatewayImage::record(
                version: $plan->target_version,
                image: $targetImage->canonical(),
                digest: $targetImage->digest(),
                source: $plan->manifest_source,
                buildId: $this->manifestBuildId($plan),
                operationRunId: $operationRun->id,
            ),
        ])->save();
    }

    private function writeGatewayHostAgentConfig(): null
    {
        $gatewayNode = $this->targets()->gatewayNode();

        if ($gatewayNode === null) {
            return null;
        }

        $this->gatewayHostAgentConfigs()->write($gatewayNode);
        $gatewayNode->forceFill(['orbit_agent_capable' => true])->save();

        return null;
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

    private function schedulerRecoveryCommand(?string $previousSchedulerImage): string
    {
        $scaleCommand = 'docker service scale --detach=true '.escapeshellarg(self::SchedulerService.'=1');

        if ($previousSchedulerImage === null) {
            return $scaleCommand;
        }

        return (
            'docker service update --detach=true --image '
            .escapeshellarg($previousSchedulerImage)
            .' --update-order '
            .escapeshellarg('stop-first')
            .' --update-failure-action rollback --update-monitor 60s '
            .escapeshellarg(self::SchedulerService)
            ." && {$scaleCommand}"
        );
    }

    private function swarm(): GatewaySwarmManager
    {
        return $this->swarm ?? app(GatewaySwarmManager::class);
    }

    private function operationRuns(): OperationRunRecorder
    {
        return $this->operationRuns ?? app(OperationRunRecorder::class);
    }

    private function targets(): FleetUpdateTargetSelector
    {
        return $this->targets ?? app(FleetUpdateTargetSelector::class);
    }

    private function gatewayHostAgentConfigs(): GatewayHostAgentConfigWriter
    {
        return $this->gatewayHostAgentConfigs ?? app(GatewayHostAgentConfigWriter::class);
    }

    private function gatewayHostAgentServices(): GatewayHostAgentServicePayloadBuilder
    {
        return $this->gatewayHostAgentServices ?? app(GatewayHostAgentServicePayloadBuilder::class);
    }

    private function swarmInstaller(): GatewaySwarmInstaller
    {
        return app(GatewaySwarmInstaller::class);
    }

    private function stackRenderer(): GatewaySwarmStackRenderer
    {
        return app(GatewaySwarmStackRenderer::class);
    }

    private function artifactRelay(): GatewayCliArtifactRelay
    {
        return app(GatewayCliArtifactRelay::class);
    }

    private function localExecutor(): RunsInternalCommands
    {
        return app(RunsInternalCommands::class);
    }

    private function output(RemoteShellResult $result): string
    {
        $output = trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());

        return $output !== '' ? $output : "exit code {$result->exitCode}";
    }
}
