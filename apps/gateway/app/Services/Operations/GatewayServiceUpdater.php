<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Nodes\InstalledCliArtifact;
use App\Data\Nodes\InstalledGatewayImage;
use App\Enums\Gateway\GatewayExposureMode;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmInstaller;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\Nodes\NodeHostPaths;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class GatewayServiceUpdater
{
    private const string GatewayService = 'orbit_orbit-gateway';

    private const string SchedulerService = 'orbit_orbit-scheduler';

    private const string RUNTIME_HIBERNATOR_SERVICE = 'orbit_orbit-runtime-hibernator';

    private const string OPERATIONS_REVERB_SERVICE = 'orbit_orbit-operations-reverb';

    private const int GatewayHealthCheckAttempts = 90;

    private const int GatewayHealthCheckSleepSeconds = 2;

    public function __construct(
        private readonly ?GatewaySwarmManager $swarm = null,
        private readonly ?OperationRunRecorder $operationRuns = null,
        private readonly ?FleetUpdateTargetSelector $targets = null,
        private readonly ?GatewayUpdateDaemonLifecycle $daemons = null,
    ) {}

    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $targetImage = GatewayImageReference::fromString($plan->gateway_image);
        $previousDaemonImages = $this->gatewayDaemons()->images();
        $daemonStopStarted = false;

        try {
            $daemonStopStarted = true;
            $this->runStep(
                $operationRun,
                'scheduler.stop',
                'Stopping orbit-scheduler service',
                'orbit-scheduler service stopped',
                fn (): null => $this->gatewayDaemons()->stop($previousDaemonImages),
            );

            $this->runStep(
                $operationRun,
                'migrations',
                'Running gateway migrations',
                'Gateway migrations completed',
                fn (): null => $this->runMigrations($targetImage),
            );
            $this->runStep(
                $operationRun,
                'gateway.host-cli',
                'Installing gateway host CLI',
                'Gateway host CLI installed',
                fn (): null => $this->installGatewayHostCli($operationRun, $plan, $targetImage),
            );
            // Converge leaf TLS material while this process is still the live
            // gateway task with ORBIT_HOST_PATH_PREFIX host mounts. Running
            // after force service replacement can leave config-root leaves
            // updated while host/Caddy serving paths stay incomplete.
            $this->runStep(
                $operationRun,
                'gateway.leaf',
                'Converging gateway leaf certificate SANs',
                'Gateway leaf certificate SANs converged',
                function (): null {
                    $this->convergeGatewayLeafServingArtifacts();

                    return null;
                },
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
                fn (): null => $this->gatewayDaemons()->start($targetImage, $previousDaemonImages),
            );
            $this->runStep(
                $operationRun,
                'gateway.stack',
                'Converging gateway Swarm stack',
                'Gateway Swarm stack converged',
                fn (): null => $this->convergeGatewayStack($targetImage, $plan),
            );
            $this->recordInstalledGatewayImage($operationRun, $plan, $targetImage);
        } catch (Throwable $throwable) {
            if ($daemonStopStarted) {
                $this->gatewayDaemons()->recover($operationRun, $previousDaemonImages, $throwable);
            }

            throw $throwable;
        }
    }

    private function installGatewayHostCli(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        GatewayImageReference $targetImage,
    ): null {
        $gatewayNode = $this->targets()->gatewayNode();

        if (! $gatewayNode instanceof Node) {
            return null;
        }

        $gatewayCliArtifact = $this->gatewayHostCliArtifact($operationRun, $plan, $gatewayNode);
        $this->swarm()->installHostCli(
            image: $targetImage,
            artifactUrl: $gatewayCliArtifact['url'],
            sha256: $gatewayCliArtifact['sha256'],
            installRoot: $this->gatewayInstallRoot($gatewayNode),
            homeDirectory: NodeHostPaths::homeDirectoryFor($gatewayNode->platform, $gatewayNode->user),
            binPath: FleetUpdateNodeCliLauncher::binPath($gatewayNode),
        );

        $this->recordInstalledGatewayHostCli($operationRun, $plan, $gatewayNode);

        return null;
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

        $gatewayNode->forceFill(['installed_agent' => null])->save();
    }

    private function runMigrations(GatewayImageReference $targetImage): null
    {
        $this->swarm()->runGatewayMigrations($targetImage);

        return null;
    }

    private function updateGatewayService(GatewayImageReference $targetImage): null
    {
        $this->swarm()->forceUpdateServiceImage(self::GatewayService, $targetImage, 'start-first');
        $this->waitForGatewayHealth($targetImage);

        return null;
    }

    private function convergeGatewayStack(GatewayImageReference $targetImage, OperationUpdatePlan $plan): null
    {
        $this->swarmInstaller()->bootstrapRuntimeConfig();
        $this->loadOperationsReverbImageArchive($plan);

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
        $this->waitForServiceReplica(self::RUNTIME_HIBERNATOR_SERVICE);
        $this->waitForServiceReplica(self::OPERATIONS_REVERB_SERVICE);

        return null;
    }

    private function convergeGatewayLeafServingArtifacts(): void
    {
        $gatewayNode = $this->targets()->gatewayNode();

        if (! $gatewayNode instanceof Node) {
            return;
        }

        $wireguardAddress = trim((string) $gatewayNode->wireguard_address);

        if ($wireguardAddress === '' || filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Gateway node is missing a valid WireGuard API address for leaf convergence.');
        }

        $this->swarmInstaller()->convergeGatewayLeafServing(
            wireguardAddress: $wireguardAddress,
            exposureMode: $this->gatewayExposureMode(),
            configRoot: $this->configRoot(),
            wireguardCidr: $this->gatewayWireguardCidr($gatewayNode),
        );
    }

    private function gatewayWireguardCidr(Node $gatewayNode): string
    {
        $settings = $gatewayNode
            ->roleAssignments
            ->first(fn ($assignment): bool => (string) $assignment->role === 'vpn')
            ?->settings;

        if (is_array($settings)) {
            $cidr = $settings['wireguard_cidr'] ?? null;

            if (is_string($cidr) && trim($cidr) !== '') {
                return trim($cidr);
            }
        }

        return '10.6.0.0/24';
    }

    private function loadOperationsReverbImageArchive(OperationUpdatePlan $plan): void
    {
        $artifact = $this->operationsReverbImageArtifact($plan);

        if ($artifact === null) {
            return;
        }

        $this->swarm()->loadImageArchive($artifact['url'], $artifact['sha256']);
    }

    /**
     * @return array{url: string, sha256: string}|null
     */
    private function operationsReverbImageArtifact(OperationUpdatePlan $plan): ?array
    {
        $artifacts = $plan->manifest_snapshot['role_image_artifacts'] ?? null;

        if (! is_array($artifacts)) {
            return null;
        }

        $artifact = $artifacts['orbit-websocket'] ?? null;

        if (! is_array($artifact)) {
            return null;
        }

        $url = $artifact['url'] ?? null;
        $sha256 = $artifact['sha256'] ?? null;

        if (! is_string($url) || trim($url) === '' || ! is_string($sha256) || trim($sha256) === '') {
            return null;
        }

        return [
            'url' => trim($url),
            'sha256' => trim($sha256),
        ];
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

    private function manifestBuildId(OperationUpdatePlan $plan): ?string
    {
        $buildId = $plan->manifest_snapshot['build_id'] ?? null;

        return is_string($buildId) && $buildId !== '' ? $buildId : null;
    }

    private function shaPrefix(string $sha256): string
    {
        return substr(strtolower($sha256), offset: 0, length: 12);
    }

    private function swarm(): GatewaySwarmManager
    {
        return $this->swarm ?? app(GatewaySwarmManager::class);
    }

    private function operationRuns(): OperationRunRecorder
    {
        return $this->operationRuns ?? app(OperationRunRecorder::class);
    }

    private function gatewayDaemons(): GatewayUpdateDaemonLifecycle
    {
        return $this->daemons ?? app(GatewayUpdateDaemonLifecycle::class);
    }

    private function targets(): FleetUpdateTargetSelector
    {
        return $this->targets ?? app(FleetUpdateTargetSelector::class);
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
}
