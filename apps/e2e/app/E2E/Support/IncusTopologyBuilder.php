<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Vpn\WgEasyServiceInstaller;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

class IncusTopologyBuilder
{
    private ?string $remoteBundleDir = null;

    private ?string $remoteOrbitBinaryBundleDir = null;

    private ?string $remoteSourcePath = null;

    private string $lastPreparedBakeOutput = '';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $provisionFingerprint = null;

    private const string GatewayWireGuardIp = '10.6.0.2';

    private const string OperatorWireGuardIp = '10.6.0.3';

    private const string DevWireGuardIp = '10.6.0.4';

    private const string ProdWireGuardIp = '10.6.0.5';

    private const string AgentWireGuardIp = '10.6.0.6';

    private const string IngressWireGuardIp = '10.6.0.7';

    private const string AppDevelopmentRuntimeUser = 'orbit';

    private const string PreparedSourceMountPath = '/mnt/orbit-source';

    private readonly E2EPhaseTimer $timer;

    public function __construct(
        protected readonly IncusHost $host,
        ?E2EPhaseTimer $timer = null,
    ) {
        $this->timer = $timer ?? new E2EPhaseTimer;
    }

    /**
     * Stage a remote bundle directory that the builder will use to install the
     * operator node. Gateway and app roles are then provisioned through
     * gateway-local internal bake commands or public CLI flows from that
     * operator node, depending on the topology stage.
     */
    public function useBundle(string $remoteBundleDir): void
    {
        $this->remoteBundleDir = $remoteBundleDir;
        $this->remoteOrbitBinaryBundleDir = $remoteBundleDir;
        $this->remoteSourcePath = null;
    }

    public function useSourcePath(string $remoteSourcePath): void
    {
        $this->remoteSourcePath = rtrim($remoteSourcePath, '/');
        $this->remoteBundleDir = null;
        $this->remoteOrbitBinaryBundleDir = null;
    }

    public function useOrbitBinaryBundle(string $remoteBundleDir): void
    {
        $this->remoteOrbitBinaryBundleDir = rtrim($remoteBundleDir, '/');
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    public function useProvisionFingerprint(array $fingerprint): void
    {
        $this->provisionFingerprint = $fingerprint;
    }

    /**
     * Build every prerequisite topology stage up to the requested kind,
     * snapshot each role with that stage name, and return the templates for
     * the requested kind.
     *
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    public function build(E2ETopologyKind $kind, bool $replaceExisting = false): array
    {
        $resumeCheckpoints = [];

        if ($replaceExisting && $this->provisionFingerprint !== null) {
            $checkpointPlan = $this->checkpointPlan($kind);

            if ($checkpointPlan['complete'] !== null) {
                return $checkpointPlan['complete'];
            }

            $resumeCheckpoints = $checkpointPlan['resume'];
        }

        $plan = $this->timer->measure('preflight', fn (): array => $this->validatePreFlight($kind, $replaceExisting, $resumeCheckpoints));

        $workDirectory = $this->timer->measure('workdir', fn (): string => $this->createWorkDirectory());

        try {
            $key = $this->timer->measure('ssh-key', fn (): SshKeyPair => $this->createSshKeyPair($workDirectory));
            $manifests = $this->buildStages($plan['target'], $key, $plan['reusableBase'], $resumeCheckpoints);

            return $manifests[$kind->value]
                ?? $manifests[E2EPreparedTopology::incusSourceKindFor($kind)->value]
                ?? throw new RuntimeException("Prepared topology manifest [{$kind->value}] was not built.");
        } finally {
            $this->timer->measure('workdir.cleanup', fn () => $this->host->run('rm -rf '.escapeshellarg((string) $workDirectory)));
        }
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $resumeCheckpoints
     * @return array{
     *     target: E2ETopologyKind,
     *     reusableBase: E2ETopologyKind|null,
     * }
     */
    private function validatePreFlight(E2ETopologyKind $kind, bool $replaceExisting, array $resumeCheckpoints = []): array
    {
        $baseImage = $this->host->config->baseImage;

        if (! $this->host->imageExists($baseImage)) {
            throw new RuntimeException("Required base image [{$baseImage}] not found on host.");
        }

        if ($this->remoteBundleDir === null && $this->remoteSourcePath === null) {
            throw new RuntimeException(
                'No source checkout or provisioning bundle has been staged. Call useSourcePath() or useBundle() before build().'
            );
        }

        $reusableBase = $replaceExisting
            ? $this->resolveReusableBaseStage($kind)
            : null;

        $preservedTemplates = array_column($resumeCheckpoints, 'name');

        foreach ($this->templateNamesForRefresh($kind, $reusableBase, includeLegacyNames: $replaceExisting) as $name) {
            if (in_array($name, $preservedTemplates, true)) {
                continue;
            }

            if (! $this->host->instanceExists($name)) {
                continue;
            }

            if (! $replaceExisting) {
                throw new RuntimeException("Template instance [{$name}] already exists.");
            }

            $result = $this->host->deleteInstance($name);

            if (! $result->successful()) {
                throw new RuntimeException("Could not delete existing template [{$name}]: {$result->errorOutput()}");
            }
        }

        return [
            'target' => $kind,
            'reusableBase' => $reusableBase,
        ];
    }

    private function resolveReusableBaseStage(E2ETopologyKind $kind): ?E2ETopologyKind
    {
        if ($kind === E2ETopologyKind::OperatorGatewayAppdevAppprodAgent
            || $kind === E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket) {
            return null;
        }

        if ($kind === E2ETopologyKind::OperatorGatewayAppprodIngress) {
            return $this->stageSnapshotsAvailable(E2ETopologyKind::OperatorGateway)
                ? E2ETopologyKind::OperatorGateway
                : null;
        }

        $stages = $this->stagesThrough($kind);
        array_pop($stages);

        foreach (array_reverse($stages) as $stage) {
            if ($this->stageSnapshotsAvailable($stage)) {
                return $stage;
            }
        }

        return null;
    }

    private function stageSnapshotsAvailable(E2ETopologyKind $stage): bool
    {
        $snapshot = IncusTopologyTemplate::snapshotName($stage);

        foreach (IncusTopologyTemplate::rolesFor($stage) as $role) {
            $template = IncusTopologyTemplate::templateName($stage, $role);

            if (! $this->host->instanceExists($template)) {
                return false;
            }

            if (! $this->host->snapshotExists($template, $snapshot)) {
                return false;
            }
        }

        return true;
    }

    private function createWorkDirectory(): string
    {
        $result = $this->host->run('mktemp -d /tmp/orbit-topology-builder-XXXXXX');

        if (! $result->successful()) {
            throw new RuntimeException('Could not create work directory on host.');
        }

        return trim($result->output());
    }

    private function createSshKeyPair(string $workDirectory): SshKeyPair
    {
        $privateKeyPath = "{$workDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";

        $result = $this->host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg('orbit-topology-builder'),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Could not create SSH key pair: {$result->errorOutput()}");
        }

        return new SshKeyPair($privateKeyPath, $publicKeyPath);
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $resumeCheckpoints
     * @return array<string, list<array{role: string, name: string, snapshot: string}>>
     */
    private function buildStages(E2ETopologyKind $target, SshKeyPair $key, ?E2ETopologyKind $reusableBase, array $resumeCheckpoints = []): array
    {
        $manifests = [];
        $instances = [];
        $stages = $this->stagesThrough($target);
        $startIndex = 0;

        if ($reusableBase !== null && ! $this->usesCopiedReusableBase($target, $reusableBase)) {
            $this->deleteSnapshotsAfterReusableBase($target, $reusableBase);
            $this->restoreReusableBaseStage($reusableBase);
        }

        if ($reusableBase !== null) {
            $baseIndex = array_search($reusableBase, $stages, true);

            if ($baseIndex === false) {
                throw new RuntimeException("Reusable base [{$reusableBase->value}] is not a prerequisite for [{$target->value}].");
            }

            $startIndex = $baseIndex + 1;
        }

        if ($this->canResumePreparedCheckpoint($target, $resumeCheckpoints)) {
            $targetIndex = array_search($target, $stages, true);

            if ($targetIndex !== false) {
                $startIndex = max($startIndex, $targetIndex);
            }
        }

        foreach (array_slice($stages, $startIndex) as $stage) {
            $instances = match ($stage) {
                E2ETopologyKind::Operator => $this->buildOperatorStage($key),
                E2ETopologyKind::OperatorGateway => $this->buildGatewayStage($key),
                E2ETopologyKind::OperatorGatewayAppdev => $this->buildDevelopmentAppStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprod => $this->buildProductionAppStage($key),
                E2ETopologyKind::OperatorGatewayAgent => $this->buildAgentOnlyStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => $this->buildPreparedFullStage($key, $stage === $target ? $resumeCheckpoints : []),
                E2ETopologyKind::OperatorGatewayAppdevAppprodIngress => $this->buildPreparedDedicatedIngressStage($key),
                E2ETopologyKind::OperatorGatewayAppprodIngress => $this->buildIngressProductionStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket => $this->buildPreparedFullWebSocketStage($key, $stage === $target ? $resumeCheckpoints : []),
                E2ETopologyKind::OperatorGatewayAppdevWebsocket,
                E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket => throw new RuntimeException("Build websocket topology source [{$stage->value}] through [".E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket->value.'].'),
            };

            $stageManifest = $this->finalizeInstances($stage, $instances);
            $manifests[$stage->value] = $stageManifest;
            $this->recordCheckpointManifest($target, $stageManifest, $stage === $target);
        }

        return $manifests;
    }

    /**
     * @return array{
     *     complete: list<array{role: string, name: string, snapshot: string}>|null,
     *     resume: list<array{role: string, name: string, snapshot: string}>,
     * }
     */
    private function checkpointPlan(E2ETopologyKind $kind): array
    {
        $manifest = new E2EProvisionCheckpointStore($this->host)->read($kind);

        $roles = IncusTopologyTemplate::rolesFor($kind);
        $validCheckpoints = E2EProvisionCheckpointManifest::validCheckpoints(
            manifest: $manifest,
            currentFingerprint: (array) $this->provisionFingerprint,
            snapshotExists: fn (string $template, string $snapshot): bool => $this->host->snapshotExists($template, $snapshot),
        );
        $validRoles = array_column($validCheckpoints, 'role');

        if (($manifest['complete'] ?? false) === true && array_values($validRoles) === array_values($roles)) {
            return [
                'complete' => $validCheckpoints,
                'resume' => [],
            ];
        }

        return [
            'complete' => null,
            'resume' => $this->canResumePreparedCheckpoint($kind, $validCheckpoints) ? $validCheckpoints : [],
        ];
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $checkpoints
     */
    private function canResumePreparedCheckpoint(E2ETopologyKind $kind, array $checkpoints): bool
    {
        if (! in_array($kind, [
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        ], true)) {
            return false;
        }

        $roles = array_column($checkpoints, 'role');

        return in_array('operator', $roles, true)
            && in_array('gateway', $roles, true);
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $checkpoints
     */
    private function recordCheckpointManifest(E2ETopologyKind $target, array $checkpoints, bool $complete): void
    {
        if ($this->provisionFingerprint === null) {
            return;
        }

        $store = new E2EProvisionCheckpointStore($this->host);
        $existing = E2EProvisionCheckpointManifest::checkpoints($store->read($target));
        $merged = [];

        foreach ([...$existing, ...$checkpoints] as $checkpoint) {
            $merged[$checkpoint['role']] = $checkpoint;
        }

        $store->write($target, E2EProvisionCheckpointManifest::create(
            kind: $target,
            fingerprint: $this->provisionFingerprint,
            checkpoints: array_values($merged),
            complete: $complete,
        ));
    }

    private function usesCopiedReusableBase(E2ETopologyKind $target, E2ETopologyKind $reusableBase): bool
    {
        return $target === E2ETopologyKind::OperatorGatewayAppprodIngress
            && $reusableBase === E2ETopologyKind::OperatorGateway;
    }

    private function restoreReusableBaseStage(E2ETopologyKind $stage): void
    {
        $snapshot = IncusTopologyTemplate::snapshotName($stage);
        $names = array_map(
            static fn (string $role): string => IncusTopologyTemplate::templateName($stage, $role),
            IncusTopologyTemplate::rolesFor($stage),
        );

        $result = $this->timer->measure("base.stop.{$stage->value}", fn () => $this->host->stopInstancesIfRunning($names));
        if (! $result->successful()) {
            throw new RuntimeException("Could not stop reusable base [{$stage->value}]: {$result->errorOutput()}");
        }

        $result = $this->timer->measure("base.restore.{$stage->value}", fn () => $this->host->restoreSnapshotsConcurrently($names, $snapshot));
        if (! $result->successful()) {
            throw new RuntimeException("Could not restore reusable base [{$stage->value}]: {$result->errorOutput()}");
        }
    }

    private function deleteSnapshotsAfterReusableBase(E2ETopologyKind $target, E2ETopologyKind $reusableBase): void
    {
        $baseRoles = IncusTopologyTemplate::rolesFor($reusableBase);
        $deleted = [];

        foreach ($this->stagesAfter($target, $reusableBase) as $stage) {
            $snapshot = IncusTopologyTemplate::snapshotName($stage);

            foreach (IncusTopologyTemplate::rolesFor($stage) as $role) {
                if (! in_array($role, $baseRoles, true)) {
                    continue;
                }

                $template = IncusTopologyTemplate::templateName($reusableBase, $role);
                $key = "{$template}/{$snapshot}";

                if (in_array($key, $deleted, true)) {
                    continue;
                }

                $deleted[] = $key;
                $result = $this->timer->measure(
                    "base.delete-downstream-snapshot.{$stage->value}.{$role}",
                    fn () => $this->host->deleteSnapshot($template, $snapshot),
                );

                if (! $result->successful()) {
                    throw new RuntimeException("Could not delete downstream snapshot [{$template}/{$snapshot}]: {$result->errorOutput()}");
                }
            }
        }
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function stagesThrough(E2ETopologyKind $target): array
    {
        if ($target === E2ETopologyKind::OperatorGatewayAppdevAppprodAgent) {
            return [
                E2ETopologyKind::Operator,
                E2ETopologyKind::OperatorGateway,
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            ];
        }

        if ($target === E2ETopologyKind::OperatorGatewayAppprodIngress) {
            return [
                E2ETopologyKind::Operator,
                E2ETopologyKind::OperatorGateway,
                E2ETopologyKind::OperatorGatewayAppprodIngress,
            ];
        }

        if ($target === E2ETopologyKind::OperatorGatewayAppdevAppprodIngress) {
            return [
                E2ETopologyKind::Operator,
                E2ETopologyKind::OperatorGateway,
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
                E2ETopologyKind::OperatorGatewayAppdevAppprodIngress,
            ];
        }

        if ($target === E2ETopologyKind::OperatorGatewayAppdevWebsocket
            || $target === E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket
            || $target === E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket) {
            return [
                E2ETopologyKind::Operator,
                E2ETopologyKind::OperatorGateway,
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
            ];
        }

        if ($target === E2ETopologyKind::OperatorGatewayAgent) {
            return [
                E2ETopologyKind::Operator,
                E2ETopologyKind::OperatorGateway,
                E2ETopologyKind::OperatorGatewayAgent,
            ];
        }

        $stages = $this->stageOrder();

        $targetIndex = array_search($target, $stages, true);

        if ($targetIndex === false) {
            throw new RuntimeException("Unsupported topology kind [{$target->value}].");
        }

        return array_slice($stages, 0, $targetIndex + 1);
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function stageOrder(): array
    {
        return [
            E2ETopologyKind::Operator,
            E2ETopologyKind::OperatorGateway,
            E2ETopologyKind::OperatorGatewayAppdev,
            E2ETopologyKind::OperatorGatewayAppdevAppprod,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
            E2ETopologyKind::OperatorGatewayAppdevAppprodIngress,
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket,
        ];
    }

    /**
     * @return list<string>
     */
    private function templateNamesForRefresh(E2ETopologyKind $kind, ?E2ETopologyKind $reusableBase, bool $includeLegacyNames): array
    {
        $names = [];
        $baseRoles = $reusableBase !== null
            ? IncusTopologyTemplate::rolesFor($reusableBase)
            : [];

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            if ($this->preservesReusableTemplate($kind, $role, $reusableBase)) {
                continue;
            }

            $names[] = IncusTopologyTemplate::templateName($kind, $role);
        }

        if (! $includeLegacyNames) {
            return array_reverse(array_values(array_unique($names)));
        }

        foreach ($this->stagesAfter($kind, $reusableBase) as $stage) {
            foreach (IncusTopologyTemplate::rolesFor($stage) as $role) {
                if (in_array($role, $baseRoles, true) && $stage !== $kind) {
                    continue;
                }

                if (! $this->preservesReusableTemplate($kind, $role, $reusableBase)) {
                    $names[] = E2ETopologyArtifactNamespace::incusTemplateName("orbit-template-{$role}");
                }

                $names[] = E2ETopologyArtifactNamespace::incusTemplateName("orbit-template-{$stage->value}-{$role}");

                foreach ($stage->deprecatedValues() as $deprecatedValue) {
                    $names[] = E2ETopologyArtifactNamespace::incusTemplateName("orbit-template-{$deprecatedValue}-{$role}");
                }
            }
        }

        return array_reverse(array_values(array_unique($names)));
    }

    private function preservesReusableTemplate(E2ETopologyKind $target, string $role, ?E2ETopologyKind $reusableBase): bool
    {
        if ($reusableBase === null) {
            return false;
        }

        if (! in_array($role, IncusTopologyTemplate::rolesFor($reusableBase), true)) {
            return false;
        }

        return IncusTopologyTemplate::templateName($target, $role)
            === IncusTopologyTemplate::templateName($reusableBase, $role);
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function stagesAfter(E2ETopologyKind $target, ?E2ETopologyKind $base): array
    {
        $stages = $this->stagesThrough($target);

        if ($base === null) {
            return $stages;
        }

        $baseIndex = array_search($base, $stages, true);

        if ($baseIndex === false) {
            throw new RuntimeException("Reusable base [{$base->value}] is not a prerequisite for [{$target->value}].");
        }

        return array_slice($stages, $baseIndex + 1);
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildOperatorStage(SshKeyPair $key): array
    {
        $instances = [];
        $operatorName = IncusTopologyTemplate::templateName(E2ETopologyKind::Operator, 'operator');
        $this->timer->measure('operator.launch', fn () => $this->launchBase($operatorName));
        $operator = new IncusInstance($this->host, $operatorName);
        $this->timer->measure('operator.agent.ready', fn () => $operator->waitForAgent());
        $this->timer->measure('operator.provision', fn () => $this->provisionPreparedInstance($operator, 'operator', $this->host->config->operatorUser));
        $this->timer->measure('operator.ssh-authorize', fn () => $operator->authorizeSsh($this->host->config->operatorUser, $key));
        $this->timer->measure('operator.ssh-ready', fn () => $operator->waitForSsh($this->host->config->operatorUser, $key));
        $this->timer->measure('operator.ipv4', fn (): string => $operator->waitForIpv4());
        $this->timer->measure('operator.provisioning-ssh-key', fn () => $this->installPrivateSshKey($operator, $key, $this->host->config->operatorUser));
        $this->timer->measure('operator.identity', fn () => E2EOperatorIdentity::ensure($operator, $this->host->config->operatorUser, $key));
        $instances['operator'] = $operator;

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildGatewayStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator'], $key);
        $operator = $instances['operator'];
        $gateway = $this->launchBaseRole('gateway', $key);
        $gatewayIp = $this->timer->measure('gateway.ipv4', fn (): string => $gateway->waitForIpv4());
        $instances['gateway'] = $gateway;

        $this->timer->measure('gateway.provision', fn () => $this->provisionPreparedInstance($gateway, 'gateway', 'orbit'));
        $this->timer->measure('gateway.real-wireguard', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('gateway.bootstrap-local', fn () => $this->bootstrapGatewayLocal($gateway, $gatewayIp));
        $this->timer->measure('gateway.trust-operator', fn () => $this->trustGatewayCaOnOperator($operator, $gateway, $key));
        $this->timer->measure('gateway.seed-operator', fn () => E2EGatewayApi::seedOperatorIdentity($gateway, self::OperatorWireGuardIp, $this->host->config->operatorUser));
        $this->timer->measure('gateway.retarget-operator', fn () => $this->retargetOperator($operator, $gatewayIp, $key));
        $this->timer->measure('gateway.use-wireguard-url', fn () => $this->useWireGuardGatewayUrl($operator, $key));
        $this->timer->measure('gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($gateway, $key));
        $this->timer->measure('gateway.api.start', fn () => E2EGatewayApi::start($gateway, 'template-gateway'));
        $this->timer->measure('gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($operator, $this->host->config->operatorUser, $key));
        $this->timer->measure('gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($gateway));

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildDevelopmentAppStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator', 'gateway'], $key);

        $this->timer->measure('dev.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('dev.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-dev'));
        $this->timer->measure('dev.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('dev.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));

        $dev = $this->launchBaseRole('dev', $key);
        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $dev->waitForIpv4());
        $instances['dev'] = $dev;

        $this->timer->measure('dev.node-new', fn () => $this->runAppNodeNew(
            $instances['operator'],
            $key,
            'app-dev-1',
            $devIp,
            'development',
            'test',
        ));
        $this->timer->measure('dev.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildProductionAppStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator', 'gateway', 'dev'], $key);

        $this->timer->measure('prod.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prod.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-prod'));
        $this->timer->measure('prod.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prod.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));

        $prod = $this->launchBaseRole('prod', $key);
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $prod->waitForIpv4());
        $instances['prod'] = $prod;

        $this->timer->measure('prod.node-new', fn () => $this->runAppNodeNew(
            $instances['operator'],
            $key,
            'app-prod-1',
            $prodIp,
            'production',
            withIngress: true,
        ));
        $this->timer->measure('prod.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildAgentOnlyStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator', 'gateway'], $key);

        $this->timer->measure('agent.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('agent.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-agent'));
        $this->timer->measure('agent.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('agent.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));

        $agent = $this->launchBaseRole('agent', $key, E2ETopologyKind::OperatorGatewayAgent);
        $agentIp = $this->timer->measure('agent.ipv4', fn (): string => $agent->waitForIpv4());
        $instances['agent'] = $agent;

        $this->timer->measure('agent.node-new', fn () => $this->runAgentNodeNew(
            $instances['operator'],
            $key,
            'agent-1',
            $agentIp,
        ));
        $this->timer->measure('agent.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return $instances;
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $resumeCheckpoints
     * @return array<string, IncusInstance>
     */
    private function buildPreparedFullStage(SshKeyPair $key, array $resumeCheckpoints = []): array
    {
        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgent;
        $resumedInstances = $this->restorePreparedCheckpointInstances($resumeCheckpoints, $key);
        $instances = array_intersect_key($resumedInstances, array_flip(['operator', 'gateway']));

        if (! isset($instances['operator'], $instances['gateway'])) {
            $resumedInstances = [];
            $instances = $this->startTemplateRoles(['operator', 'gateway'], $key);
        }

        $this->timer->measure('prepared.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-prepared-full'));
        $this->timer->measure('prepared.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));
        $this->timer->measure('prepared.gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($instances['gateway'], $key));

        $rolesToBake = [];
        $downstreamStatuses = [];

        $rolesToPrepare = [];

        foreach (['dev', 'prod', 'agent'] as $role) {
            if (isset($resumedInstances[$role])) {
                $instances[$role] = $resumedInstances[$role];
                $downstreamStatuses[$role] = 0;

                continue;
            }

            $rolesToPrepare[] = $role;
            $rolesToBake[] = $role;
        }

        $instances = [
            ...$instances,
            ...$this->timer->measure('prepared.downstream.prepare', fn (): array => $this->launchBaseRolesInParallel($rolesToPrepare, $key, $kind, 'prepared.downstream.prepare')),
        ];

        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $instances['dev']->waitForIpv4());
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $instances['prod']->waitForIpv4());
        $agentIp = $this->timer->measure('agent.ipv4', fn (): string => $instances['agent']->waitForIpv4());

        if ($rolesToBake !== []) {
            $this->timer->measure('prepared.downstream.real-wireguard', fn () => $this->installRealWireGuard($instances));
        }

        if (in_array('dev', $rolesToBake, true)) {
            $this->timer->measure('prepared.dev.runtime-prerequisites', fn () => $this->installPreparedAppRuntimePrerequisites($instances['dev']));
            $this->timer->measure('prepared.dev.runtime-ssh-authorize', fn () => $this->authorizePreparedRuntimeUserSsh($instances['dev'], $key));
        }

        if ($rolesToBake !== []) {
            $downstreamStatuses = [
                ...$downstreamStatuses,
                ...$this->timer->measure('prepared.downstream.bake', fn (): array => $this->runPreparedDownstreamBakeInParallel(
                    $instances['gateway'],
                    $devIp,
                    $prodIp,
                    $agentIp,
                    $rolesToBake,
                    timerPrefix: 'prepared.downstream.bake',
                )),
            ];
        }
        $this->checkpointSuccessfulPreparedRolesOrFail(
            $kind,
            $instances,
            $downstreamStatuses,
        );
        $this->timer->measure('prepared.gateway.api.ready-after-node-new', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared.e2e-deps', fn () => $this->installE2EBaseDependencies($instances));
        $this->timer->measure('dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
        $this->timer->measure('prepared.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildPreparedDedicatedIngressStage(SshKeyPair $key): array
    {
        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodIngress;
        $instances = $this->startTemplateRoles(['operator', 'gateway', 'dev', 'prod'], $key, $kind);

        $this->timer->measure('prepared-ingress.gateway.bundle-overlay', fn () => $this->applyBundleOverlay($instances['gateway'], 'gateway', $key));
        $this->timer->measure('prepared-ingress.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared-ingress.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-prepared-dedicated-ingress'));
        $this->timer->measure('prepared-ingress.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared-ingress.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));
        $this->timer->measure('prepared-ingress.gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($instances['gateway'], $key));

        $ingress = $this->launchBaseRole('ingress', $key, $kind);
        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $instances['dev']->waitForIpv4());
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $instances['prod']->waitForIpv4());
        $ingressIp = $this->timer->measure('ingress.ipv4', fn (): string => $ingress->waitForIpv4());

        $instances['ingress'] = $ingress;

        $this->timer->measure('prepared-ingress.downstream.bake', fn () => $this->runPreparedDedicatedIngressBakeInParallel(
            $instances['gateway'],
            $devIp,
            $prodIp,
            $ingressIp,
        ));
        $this->timer->measure('prepared-ingress.gateway.api.ready-after-downstream-bake', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared-ingress.dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
        $this->timer->measure('prepared-ingress.e2e-deps', fn () => $this->installE2EBaseDependencies($instances));
        $this->timer->measure('prepared-ingress.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return $instances;
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $resumeCheckpoints
     * @return array<string, IncusInstance>
     */
    private function buildPreparedFullWebSocketStage(SshKeyPair $key, array $resumeCheckpoints = []): array
    {
        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
        $resumedInstances = $this->restorePreparedCheckpointInstances($resumeCheckpoints, $key);
        $instances = array_intersect_key($resumedInstances, array_flip(['operator', 'gateway']));

        if (! isset($instances['operator'], $instances['gateway'])) {
            $resumedInstances = [];
            $instances = $this->startTemplateRoles(['operator', 'gateway'], $key);
        }

        $this->timer->measure('prepared-websocket.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared-websocket.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-prepared-full-websocket'));
        $this->timer->measure('prepared-websocket.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared-websocket.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));
        $this->timer->measure('prepared-websocket.gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($instances['gateway'], $key));

        $rolesToBake = [];
        $downstreamStatuses = [];

        $rolesToPrepare = [];

        foreach (['dev', 'prod', 'agent'] as $role) {
            if (isset($resumedInstances[$role])) {
                $instances[$role] = $resumedInstances[$role];
                $downstreamStatuses[$role] = 0;

                continue;
            }

            $rolesToPrepare[] = $role;
            $rolesToBake[] = $role;
        }

        $instances = [
            ...$instances,
            ...$this->timer->measure('prepared-websocket.downstream.prepare', fn (): array => $this->launchBaseRolesInParallel($rolesToPrepare, $key, $kind, 'prepared-websocket.downstream.prepare')),
        ];

        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $instances['dev']->waitForIpv4());
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $instances['prod']->waitForIpv4());
        $agentIp = $this->timer->measure('agent.ipv4', fn (): string => $instances['agent']->waitForIpv4());

        if ($rolesToBake !== []) {
            $this->timer->measure('prepared-websocket.downstream.real-wireguard', fn () => $this->installRealWireGuard($instances));
        }

        if (in_array('dev', $rolesToBake, true)) {
            $this->timer->measure('prepared-websocket.dev.runtime-prerequisites', fn () => $this->installPreparedAppRuntimePrerequisites(
                $instances['dev'],
                includeGatewayImage: true,
            ));
            $this->timer->measure('prepared-websocket.dev.runtime-ssh-authorize', fn () => $this->authorizePreparedRuntimeUserSsh($instances['dev'], $key));
        }

        if ($rolesToBake === [] && $resumeCheckpoints !== []) {
            $this->timer->measure('prepared-websocket.downstream.real-wireguard', fn () => $this->installRealWireGuard($instances));
            $this->timer->measure('prepared-websocket.dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
            $downstreamStatuses['websocket'] = $this->timer->measure('prepared-websocket.websocket.bake', fn (): int => $this->runPreparedWebSocketBake(
                $instances['gateway'],
                $devIp,
            ));
        } elseif ($rolesToBake !== []) {
            $downstreamStatuses = [
                ...$downstreamStatuses,
                ...$this->timer->measure('prepared-websocket.downstream.bake', fn (): array => $this->runPreparedDownstreamAndWebSocketBakeInParallel(
                    $instances['gateway'],
                    $devIp,
                    $prodIp,
                    $agentIp,
                    $rolesToBake,
                    timerPrefix: 'prepared-websocket.downstream.bake',
                    beforeDevelopmentBake: null,
                    afterDevelopmentBake: function () use ($instances): void {
                        $this->timer->measure('prepared-websocket.dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
                    },
                )),
            ];
        }
        $this->checkpointSuccessfulPreparedRolesOrFail(
            $kind,
            $instances,
            $downstreamStatuses,
        );
        $this->timer->measure('prepared-websocket.real-wireguard', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared-websocket.gateway.api.ready-after-downstream-bake', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared-websocket.e2e-deps', fn () => $this->installE2EBaseDependencies($instances));

        return $instances;
    }

    private function runPreparedWebSocketBake(IncusInstance $gateway, string $devHost): int
    {
        $webSocketCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-websocket-node',
            escapeshellarg('app-dev-1'),
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg(self::AppDevelopmentRuntimeUser),
            '--redis-node='.escapeshellarg('app-dev-1'),
            '--converge-runtime',
        ]));

        return $gateway->exec($webSocketCommand, timeoutSeconds: 900)->successful() ? 0 : 1;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildIngressProductionStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator', 'gateway'], $key, E2ETopologyKind::OperatorGatewayAppprodIngress);
        $gatewayIp = $this->timer->measure('ingress.gateway.ipv4', fn (): string => $instances['gateway']->waitForIpv4());

        $this->timer->measure('ingress.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('ingress.gateway.bootstrap-local', fn () => $this->bootstrapGatewayLocal($instances['gateway'], $gatewayIp));
        $this->timer->measure('ingress.gateway.retarget-operator', fn () => $this->retargetOperator($instances['operator'], $gatewayIp, $key));
        $this->timer->measure('ingress.gateway.use-wireguard-url', fn () => $this->useWireGuardGatewayUrl($instances['operator'], $key));
        $this->timer->measure('ingress.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-ingress'));
        $this->timer->measure('ingress.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('ingress.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));
        $this->timer->measure('ingress.gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($instances['gateway'], $key));

        $prod = $this->launchBaseRole('prod', $key, E2ETopologyKind::OperatorGatewayAppprodIngress);
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $prod->waitForIpv4());
        $instances['prod'] = $prod;

        $this->timer->measure('prod.node-new', fn () => $this->runAppNodeNew(
            $instances['operator'],
            $key,
            'app-prod-1',
            $prodIp,
            'production',
            withIngress: true,
        ));
        $this->timer->measure('ingress.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return [
            'operator' => $instances['operator'],
            'gateway' => $instances['gateway'],
            'prod' => $instances['prod'],
        ];
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function installE2EBaseDependencies(array $instances): void
    {
        foreach ($instances as $role => $instance) {
            E2ECommand::exec(
                $instance,
                $this->installE2EBaseDependenciesCommand(),
                "Could not install E2E base dependencies on {$role}",
                timeoutSeconds: 600,
            );
        }
    }

    private function installE2EBaseDependenciesCommand(): string
    {
        $script = <<<'BASH'
missing=''
for command in composer git supervisorctl wg wg-quick dig ufw; do
    if ! command -v "$command" >/dev/null 2>&1; then
        missing="${missing} ${command}"
    fi
done

if [ -n "$missing" ]; then
    echo "prepared Incus base image is missing E2E dependencies:${missing}. Rebuild the base image and prepared topology." >&2
    exit 1
fi

if command -v systemctl >/dev/null 2>&1; then
    sudo systemctl enable --now supervisor.service || true
fi
BASH;

        return 'bash -lc '.escapeshellarg($script);
    }

    private function provisionPreparedInstance(IncusInstance $instance, string $nodeKind, string $user): void
    {
        if ($this->remoteBundleDir !== null) {
            $result = $nodeKind === 'operator'
                ? $this->host->provisionInstance($instance->name(), $nodeKind, $this->remoteBundleDir, $user)
                : $this->host->provisionInstance($instance->name(), $nodeKind, $this->remoteBundleDir);

            if (! $result->successful()) {
                throw new RuntimeException("Could not provision {$instance->name()}: {$result->errorOutput()}");
            }

            return;
        }

        $this->installSourceMountedRuntime($instance, $user);

        if ($this->remoteOrbitBinaryBundleDir !== null) {
            $this->installOrbitBinaryFromBundle($instance, $user);
        }
    }

    private function installOrbitBinaryFromBundle(IncusInstance $instance, string $user): void
    {
        $bundleDir = $this->remoteOrbitBinaryBundleDir;

        if ($bundleDir === null) {
            throw new RuntimeException('No Orbit CLI binary bundle has been staged.');
        }

        $name = $instance->name();
        $binary = "{$bundleDir}/orbit-binary";
        $target = "/home/{$user}/orbit/bin/orbit-binary";

        $result = $this->host->run(implode("\n", [
            'set -euo pipefail',
            'test -f '.escapeshellarg($binary),
            'incus exec '.escapeshellarg($name).' -- sh -lc '.escapeshellarg('id '.escapeshellarg($user).' >/dev/null 2>&1 || useradd -m -s /bin/bash '.escapeshellarg($user)),
            'incus exec '.escapeshellarg($name).' -- sh -lc '.escapeshellarg('install -d -m 0755 -o '.escapeshellarg($user).' -g '.escapeshellarg($user).' /home/'.escapeshellarg($user).'/orbit/bin'),
            'incus file push '.escapeshellarg($binary).' '.escapeshellarg("{$name}{$target}"),
            'incus exec '.escapeshellarg($name).' -- sh -lc '.escapeshellarg('chown '.escapeshellarg($user).':'.escapeshellarg($user).' '.escapeshellarg($target).' && chmod 0755 '.escapeshellarg($target).' && ln -sf '.escapeshellarg($target).' /usr/local/bin/orbit'),
            'incus exec '.escapeshellarg($name).' -- sh -lc '.escapeshellarg('runuser -u '.escapeshellarg($user).' -- env HOME=/home/'.escapeshellarg($user).' PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin /usr/local/bin/orbit --version >/dev/null'),
        ]), timeoutSeconds: 120);

        if (! $result->successful()) {
            throw new RuntimeException("Could not install Orbit CLI binary on {$name}: {$result->errorOutput()}{$result->output()}");
        }
    }

    private function installSourceMountedRuntime(IncusInstance $instance, string $user): void
    {
        if ($this->remoteSourcePath === null) {
            throw new RuntimeException('No source checkout has been staged.');
        }

        E2ECommand::exec(
            $instance,
            $this->sourceMountedRuntimeInstallCommand($user),
            "Could not install source-mounted runtime on {$instance->name()}",
            timeoutSeconds: 900,
        );

        if ($user === 'orbit') {
            E2ECommand::exec(
                $instance,
                $this->verifySourceMountedDockerImagesCommand(),
                "Source-mounted Docker runtime images are missing on {$instance->name()}",
                timeoutSeconds: 120,
            );
        }
    }

    private function sourceMountedRuntimeInstallCommand(string $user): string
    {
        $targetPath = "/home/{$user}/orbit";
        $mirrorCommand = E2ECurrentCheckout::sourceMountedRuntimeMirrorCommand(self::PreparedSourceMountPath, $targetPath);
        $cli = "{$targetPath}/bin/orbit";

        $script = implode("\n", [
            'set -euo pipefail',
            'user='.escapeshellarg($user),
            'target='.escapeshellarg($targetPath),
            'config_root="/home/$user/.config/orbit"',
            'if ! id "$user" >/dev/null 2>&1; then useradd -m -s /bin/bash "$user"; fi',
            'install -d -m 0755 -o "$user" -g "$user" "$target"',
            'runuser -u "$user" -- bash -lc '.escapeshellarg($mirrorCommand),
            'for app in apps/gateway apps/cli; do',
            '  archive='.escapeshellarg(self::PreparedSourceMountPath).'"/'.SourceMountedCheckoutSyncer::VendorArchiveDirectory.'/$(printf "%s" "$app" | tr "/" "-")-vendor.tar"',
            '  if [ -f "$archive" ]; then',
            '    rm -rf "$target/$app/vendor"',
            '    mkdir -p "$target/$app"',
            '    tar -C "$target/$app" -xf "$archive"',
            '  elif [ -d '.escapeshellarg(self::PreparedSourceMountPath).'/"$app/vendor" ]; then',
            '    echo "Missing source-mounted vendor archive for $app at $archive" >&2',
            '    exit 1',
            '  fi',
            'done',
            'chown -R "$user:$user" "$target"',
            'install -d -m 0755 -o "$user" -g "$user" "$config_root"',
            'runuser -u "$user" -- env ORBIT_CONFIG_ROOT="$config_root" DB_CONNECTION=sqlite DB_DATABASE="$config_root/gateway.sqlite" SESSION_DRIVER=file bash -lc "cd \"$target\" && php apps/gateway/artisan migrate --force --no-interaction --ansi"',
            'touch "$config_root/source-mounted-runtime"',
            'chown "$user:$user" "$config_root/source-mounted-runtime"',
            'chmod 0755 '.escapeshellarg($cli),
            'ln -sf '.escapeshellarg($cli).' /usr/local/bin/orbit',
            'runuser -u "$user" -- env HOME="/home/$user" PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin /usr/local/bin/orbit --version >/dev/null',
        ]);

        return 'bash -lc '.escapeshellarg($script);
    }

    private function verifySourceMountedDockerImagesCommand(): string
    {
        $frankenPhpImage = (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT);
        $sourceGatewayArtisanImage = DockerTopologyProvider::sourceGatewayArtisanImage();
        $webSocketRuntimeImage = DockerTopologyProvider::webSocketRuntimeImage();
        $caddyImage = OrbitCaddyContainer::Image;
        $wgEasyImage = WgEasyServiceInstaller::Image;

        $script = implode("\n", [
            'set -euo pipefail',
            'if command -v systemctl >/dev/null 2>&1; then sudo systemctl enable --now docker || sudo systemctl start docker || true; fi',
            'for image in '.escapeshellarg($frankenPhpImage).' '.escapeshellarg($sourceGatewayArtisanImage).' '.escapeshellarg($webSocketRuntimeImage).' '.escapeshellarg($caddyImage).' '.escapeshellarg($wgEasyImage).'; do',
            '  docker image inspect "$image" >/dev/null 2>&1 || { echo "prepared Incus runtime base image is missing Docker image: $image. Rebuild it with composer e2e:prepare-base-image -- --force." >&2; exit 1; }',
            'done',
        ]);

        return 'bash -lc '.escapeshellarg($script);
    }

    private function installPreparedAppRuntimePrerequisites(IncusInstance $instance, bool $includeGatewayImage = false): void
    {
        $bundleDir = $this->remoteBundleDir;

        if ($bundleDir === null) {
            E2ECommand::exec(
                $instance,
                $this->verifySourceMountedDockerImagesCommand(),
                "Source-mounted app runtime images are missing on {$instance->name()}",
                timeoutSeconds: 120,
            );

            return;
        }

        $archives = [
            [
                'guest' => '/var/tmp/caddy-2-alpine.tar',
                'host' => "{$bundleDir}/caddy-2-alpine.tar",
            ],
            [
                'guest' => '/var/tmp/frankenphp-1-php8.5-bookworm.tar',
                'host' => "{$bundleDir}/frankenphp-1-php8.5-bookworm.tar",
            ],
            [
                'guest' => '/var/tmp/orbit-websocket-current.tar',
                'host' => "{$bundleDir}/orbit-websocket-current.tar",
            ],
        ];

        if ($includeGatewayImage) {
            $archives[] = [
                'guest' => '/var/tmp/'.E2EArtifactProdManifest::GatewayImageArchive,
                'host' => "{$bundleDir}/".E2EArtifactProdManifest::GatewayImageArchive,
            ];
        }

        foreach ($archives as $archive) {
            $push = $this->host->run(sprintf(
                'incus file push %s %s',
                escapeshellarg($archive['host']),
                escapeshellarg("{$instance->name()}{$archive['guest']}"),
            ), timeoutSeconds: 300);

            if (! $push->successful()) {
                throw new RuntimeException("Could not push runtime image archive [{$archive['host']}] into [{$instance->name()}]: {$push->errorOutput()}");
            }
        }

        E2ECommand::exec(
            $instance,
            $this->preparedAppRuntimePrerequisitesCommand(
                caddyImageArchive: '/var/tmp/caddy-2-alpine.tar',
                frankenPhpImageArchive: '/var/tmp/frankenphp-1-php8.5-bookworm.tar',
                caddyImage: OrbitCaddyContainer::Image,
                frankenPhpImage: (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT),
                webSocketImageArchive: '/var/tmp/orbit-websocket-current.tar',
                webSocketImage: DockerTopologyProvider::webSocketRuntimeImage(),
                bootstrapUser: $this->host->config->bootstrapUser,
                gatewayImageArchive: $includeGatewayImage ? '/var/tmp/'.E2EArtifactProdManifest::GatewayImageArchive : null,
                preparedGatewayImage: $includeGatewayImage ? DockerTopologyProvider::gatewayImage() : null,
            ),
            "Could not install prepared app runtime prerequisites on {$instance->name()}",
            timeoutSeconds: 900,
        );
    }

    private function authorizePreparedRuntimeUserSsh(IncusInstance $instance, SshKeyPair $key): void
    {
        $instance->authorizeSsh('orbit', $key);
    }

    private function preparedAppRuntimePrerequisitesCommand(
        string $caddyImageArchive,
        string $frankenPhpImageArchive,
        string $caddyImage,
        string $frankenPhpImage,
        string $webSocketImageArchive,
        string $webSocketImage,
        string $bootstrapUser,
        ?string $gatewayImageArchive = null,
        ?string $preparedGatewayImage = null,
    ): string {
        $gatewayImageScript = '';

        if ($gatewayImageArchive !== null && $preparedGatewayImage !== null) {
            $gatewayImageScript = sprintf(
                <<<'BASH'

sudo docker load -i %s
if sudo docker image inspect %s >/dev/null 2>&1; then
    sudo docker tag %s 'orbit-gateway:current'
fi
sudo docker image inspect 'orbit-gateway:current' >/dev/null
BASH,
                escapeshellarg($gatewayImageArchive),
                escapeshellarg($preparedGatewayImage),
                escapeshellarg($preparedGatewayImage),
            );
        }

        $script = sprintf(
            <<<'BASH'
set -euo pipefail
bootstrap_user=%s
runtime_user=orbit

if ! command -v docker >/dev/null 2>&1; then
    sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq docker.io
fi

if command -v systemctl >/dev/null 2>&1; then
    sudo systemctl enable --now docker || sudo systemctl start docker || true
fi

if getent group docker >/dev/null 2>&1; then
    sudo usermod -aG docker "$(id -un)"
    if getent passwd "$bootstrap_user" >/dev/null 2>&1; then
        sudo usermod -aG docker "$bootstrap_user"
    fi
    if getent passwd "$runtime_user" >/dev/null 2>&1; then
        sudo usermod -aG docker "$runtime_user"
    fi
fi

sudo docker load -i %s
sudo docker image inspect %s >/dev/null
sudo docker load -i %s
sudo docker image inspect %s >/dev/null%s
sudo docker load -i %s
sudo docker image inspect %s >/dev/null
if getent passwd "$bootstrap_user" >/dev/null 2>&1; then
    sudo -u "$bootstrap_user" docker image inspect %s >/dev/null
    sudo -u "$bootstrap_user" docker image inspect %s >/dev/null
    sudo -u "$bootstrap_user" docker image inspect %s >/dev/null
    if sudo docker image inspect 'orbit-gateway:current' >/dev/null 2>&1; then
        sudo -u "$bootstrap_user" docker image inspect 'orbit-gateway:current' >/dev/null
    fi
fi
if getent passwd "$runtime_user" >/dev/null 2>&1; then
    sudo -u "$runtime_user" docker image inspect %s >/dev/null
    sudo -u "$runtime_user" docker image inspect %s >/dev/null
    sudo -u "$runtime_user" docker image inspect %s >/dev/null
    if sudo docker image inspect 'orbit-gateway:current' >/dev/null 2>&1; then
        sudo -u "$runtime_user" docker image inspect 'orbit-gateway:current' >/dev/null
    fi
fi
BASH,
            escapeshellarg($bootstrapUser),
            escapeshellarg($caddyImageArchive),
            escapeshellarg($caddyImage),
            escapeshellarg($frankenPhpImageArchive),
            escapeshellarg($frankenPhpImage),
            $gatewayImageScript,
            escapeshellarg($webSocketImageArchive),
            escapeshellarg($webSocketImage),
            escapeshellarg($caddyImage),
            escapeshellarg($frankenPhpImage),
            escapeshellarg($webSocketImage),
            escapeshellarg($caddyImage),
            escapeshellarg($frankenPhpImage),
            escapeshellarg($webSocketImage),
        );

        return 'bash -lc '.escapeshellarg($script);
    }

    private function seedAppdevDatabaseAndRedis(IncusInstance $gateway): void
    {
        $encoded = base64_encode(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp());

        E2ECommand::gatewayArtisan(
            $gateway,
            'tinker --execute='.escapeshellarg("eval(base64_decode('{$encoded}'));"),
            'Could not seed app-dev database and Redis registry state',
            timeoutSeconds: 120,
        );
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, IncusInstance>
     */
    private function startTemplateRoles(array $roles, SshKeyPair $key, E2ETopologyKind $templateKind = E2ETopologyKind::Operator): array
    {
        $instances = [];

        foreach ($roles as $role) {
            $instances[$role] = $this->startTemplateRole($role, $key, $templateKind);
        }

        return $instances;
    }

    /**
     * @param  list<array{role: string, name: string, snapshot: string}>  $checkpoints
     * @return array<string, IncusInstance>
     */
    private function restorePreparedCheckpointInstances(array $checkpoints, SshKeyPair $key): array
    {
        if ($checkpoints === []) {
            return [];
        }

        $names = array_column($checkpoints, 'name');
        $stopResult = $this->timer->measure('checkpoint.stop', fn () => $this->host->stopInstancesIfRunning($names));

        if (! $stopResult->successful()) {
            throw new RuntimeException("Could not stop checkpoint templates before restore: {$stopResult->errorOutput()}");
        }

        $restoreLines = [];
        $waitLines = [];

        foreach (array_values($checkpoints) as $index => $checkpoint) {
            $pid = 'PID_RESTORE_CHECKPOINT_'.($index + 1);
            $restoreLines[] = sprintf(
                'incus snapshot restore %s %s & %s=$!',
                escapeshellarg($checkpoint['name']),
                escapeshellarg($checkpoint['snapshot']),
                $pid,
            );
            $waitLines[] = "wait \${$pid}";
        }

        $restoreResult = $this->timer->measure(
            'checkpoint.restore',
            fn () => $this->host->run(implode("\n", [...$restoreLines, ...$waitLines]), timeoutSeconds: 600),
        );

        if (! $restoreResult->successful()) {
            throw new RuntimeException("Could not restore checkpoint templates: {$restoreResult->errorOutput()}");
        }

        $startResult = $this->timer->measure('checkpoint.start', fn () => $this->host->startInstancesIfStopped($names));

        if (! $startResult->successful()) {
            throw new RuntimeException("Could not start checkpoint templates: {$startResult->errorOutput()}");
        }

        $instances = [];

        foreach ($checkpoints as $checkpoint) {
            $role = $checkpoint['role'];
            $instance = new IncusInstance($this->host, $checkpoint['name']);
            $this->timer->measure("checkpoint.agent-ready.{$role}", fn () => $instance->waitForAgent());

            if ($role === 'operator') {
                $this->timer->measure("checkpoint.ssh-authorize.{$role}", fn () => $instance->authorizeSsh($this->host->config->operatorUser, $key));
                $this->timer->measure("checkpoint.ssh-ready.{$role}", fn () => $instance->waitForSsh($this->host->config->operatorUser, $key));
            } elseif ($role !== 'gateway') {
                $this->timer->measure("checkpoint.ssh-authorize.{$role}", fn () => $instance->authorizeSsh($this->host->config->bootstrapUser, $key));
                $this->timer->measure("checkpoint.ssh-ready.{$role}", fn () => $instance->waitForSsh($this->host->config->bootstrapUser, $key));
            }

            $instances[$role] = $instance;
        }

        return $instances;
    }

    private function startTemplateRole(string $role, SshKeyPair $key, E2ETopologyKind $templateKind = E2ETopologyKind::Operator): IncusInstance
    {
        $name = IncusTopologyTemplate::templateName($templateKind, $role);

        $start = $this->timer->measure("{$role}.start", fn () => $this->host->startInstance($name));
        if (! $start->successful()) {
            throw new RuntimeException("Could not start {$name}: {$start->errorOutput()}");
        }

        $instance = new IncusInstance($this->host, $name);
        $this->timer->measure("{$role}.agent.ready", fn () => $instance->waitForAgent());

        if ($role === 'operator') {
            $this->timer->measure("{$role}.ssh-authorize", fn () => $instance->authorizeSsh($this->host->config->operatorUser, $key));
            $this->timer->measure("{$role}.ssh-ready", fn () => $instance->waitForSsh($this->host->config->operatorUser, $key));
        }

        return $instance;
    }

    private function launchBaseRole(string $role, SshKeyPair $key, E2ETopologyKind $templateKind = E2ETopologyKind::Operator): IncusInstance
    {
        $name = IncusTopologyTemplate::templateName($templateKind, $role);
        $this->timer->measure("{$role}.launch", fn () => $this->launchBase($name));
        $instance = new IncusInstance($this->host, $name);
        $this->timer->measure("{$role}.agent.ready", fn () => $instance->waitForAgent());
        if ($this->remoteSourcePath !== null && $role !== 'gateway') {
            $this->timer->measure("{$role}.source-runtime", fn () => $this->installSourceMountedRuntime($instance, $this->host->config->bootstrapUser));
        }
        $this->timer->measure("{$role}.ssh-authorize", fn () => $instance->authorizeSsh($this->host->config->bootstrapUser, $key));
        $this->timer->measure("{$role}.ssh-ready", fn () => $instance->waitForSsh($this->host->config->bootstrapUser, $key));

        return $instance;
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, IncusInstance>
     */
    private function launchBaseRolesInParallel(array $roles, SshKeyPair $key, E2ETopologyKind $templateKind, string $timerPrefix = 'prepared.downstream.prepare'): array
    {
        $roles = array_values(array_intersect(['dev', 'prod', 'agent'], $roles));

        if ($roles === []) {
            return [];
        }

        $caseLines = [];
        $startLines = [];
        $statusLines = [];
        $waitLines = [];
        $echoLines = [];

        foreach ($roles as $role) {
            $name = IncusTopologyTemplate::templateName($templateKind, $role);
            $suffix = strtoupper(str_replace('-', '_', $role));
            $pid = "PID_PREPARE_{$suffix}";
            $status = "STATUS_PREPARE_{$suffix}";
            $logPath = "/tmp/orbit-e2e-prepare-{$role}.log";

            $caseLines[] = sprintf(
                '%s) %s ;;',
                escapeshellarg($role),
                $this->launchTopologyInstanceCommand($name),
            );
            $startLines[] = sprintf('prepare_role %s %s > %s 2>&1 & %s=$!;', escapeshellarg($role), escapeshellarg($name), escapeshellarg($logPath), $pid);
            $statusLines[] = "{$status}=0;";
            $waitLines[] = sprintf(
                'wait "$%1$s" || { %2$s=$?; echo %3$s >&2; cat %4$s >&2 || true; if [ "$STATUS" -eq 0 ]; then STATUS=$%2$s; fi; }; cat %4$s || true;',
                $pid,
                $status,
                escapeshellarg("prepare {$role} failed"),
                escapeshellarg($logPath),
            );
            $echoLines[] = sprintf('echo "__orbit_prepare_status %s $%s";', $role, $status);
        }

        $sourceRuntimeInstallCommand = $this->sourceMountedRuntimeInstallCommand($this->host->config->bootstrapUser);
        $script = sprintf(
            <<<'BASH'
set -euo pipefail;

bootstrap_user=%s
bundle_dir=%s
binary_bundle_dir=%s
source_path=%s
private_key_path=%s
public_key_path=%s
timeout_seconds=%d
source_runtime_install_command=%s

now_ms() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import time; print(int(time.time() * 1000))'

        return
    fi

    echo "$(($(date +%%s) * 1000))"
}

record_timing() {
    local role="$1"
    local phase="$2"
    local start_ms="$3"
    local end_ms

    end_ms="$(now_ms)"
    echo "__orbit_prepare_timing ${role} ${phase} $((end_ms - start_ms))"
}

wait_for_agent() {
    local name="$1"
    local deadline=$((SECONDS + timeout_seconds))

    until incus exec "$name" -- true >/dev/null 2>&1; do
        if [ "$SECONDS" -ge "$deadline" ]; then
            echo "Incus agent never became ready on ${name}." >&2
            return 1
        fi

        sleep 2
    done
}

authorize_ssh() {
    local name="$1"

    incus exec "$name" -- sh -lc "install -d -m 700 -o ${bootstrap_user} -g ${bootstrap_user} /home/${bootstrap_user}/.ssh"
    incus file push "$public_key_path" "${name}/home/${bootstrap_user}/.ssh/authorized_keys"
    incus exec "$name" -- sh -lc "chown ${bootstrap_user}:${bootstrap_user} /home/${bootstrap_user}/.ssh/authorized_keys && chmod 600 /home/${bootstrap_user}/.ssh/authorized_keys && usermod -p '*' ${bootstrap_user} && (systemctl start ssh || systemctl start sshd || true)"
}

instance_ipv4() {
    local name="$1"

    incus exec "$name" -- sh -lc 'ip -o -4 addr show scope global' \
        | awk '$2 !~ /^(lo|docker0|docker_gwbridge|wg-orbit|wg0|br-|veth)/ && found != 1 { split($4, parts, "/"); print parts[1]; found = 1 }'
}

wait_for_ssh() {
    local name="$1"
    local deadline=$((SECONDS + timeout_seconds))
    local ipv4=''

    while [ "$SECONDS" -lt "$deadline" ]; do
        ipv4="$(instance_ipv4 "$name")"

        if [ -n "$ipv4" ] && ssh -i "$private_key_path" -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "${bootstrap_user}@${ipv4}" 'test "$(uname -s)" = Linux && test -r /etc/os-release' >/dev/null 2>&1; then
            return 0
        fi

        sleep 3
    done

    echo "SSH never became ready on ${name}." >&2
    return 1
}

install_orbit_binary() {
    local name="$1"
    local binary="${binary_bundle_dir}/orbit-binary"

    if [ ! -f "$binary" ]; then
        echo "Orbit binary artifact missing from prepared topology bundle: ${binary}" >&2
        return 1
    fi

    incus exec "$name" -- sh -lc 'id orbit >/dev/null 2>&1 || useradd -m -s /bin/bash orbit'
    incus exec "$name" -- sh -lc 'install -d -m 0755 -o orbit -g orbit /home/orbit/orbit/bin'
    incus file push "$binary" "${name}/home/orbit/orbit/bin/orbit-binary"
    incus exec "$name" -- sh -lc 'chown orbit:orbit /home/orbit/orbit/bin/orbit-binary && chmod 0755 /home/orbit/orbit/bin/orbit-binary && ln -sf /home/orbit/orbit/bin/orbit-binary /usr/local/bin/orbit'
    incus exec "$name" -- sh -lc 'runuser -u orbit -- env HOME=/home/orbit PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin /usr/local/bin/orbit --version >/dev/null'
}

install_source_runtime() {
    local name="$1"

    if [ -z "$source_path" ]; then
        return 1
    fi

    if incus config device get "$name" orbit-source path >/dev/null 2>&1; then
        incus config device set "$name" orbit-source source="$source_path"
        incus config device set "$name" orbit-source path='%s'
        incus config device set "$name" orbit-source shift=true
    else
        incus config device add "$name" orbit-source disk source="$source_path" path='%s' shift=true
    fi

    incus exec "$name" -- bash -lc "$source_runtime_install_command"
}

prepare_role() {
    local role="$1"
    local name="$2"
    local phase_start

    phase_start="$(now_ms)"
    case "$role" in
%s
    *) echo "Unsupported prepared role: ${role}" >&2; return 1 ;;
    esac
    record_timing "$role" launch "$phase_start"

    phase_start="$(now_ms)"
    wait_for_agent "$name"
    record_timing "$role" agent-ready "$phase_start"

    if [ -n "$binary_bundle_dir" ]; then
        phase_start="$(now_ms)"
        install_orbit_binary "$name"
        record_timing "$role" orbit-binary "$phase_start"
    elif [ -n "$source_path" ]; then
        phase_start="$(now_ms)"
        install_source_runtime "$name"
        record_timing "$role" source-runtime "$phase_start"
    else
        phase_start="$(now_ms)"
        install_orbit_binary "$name"
        record_timing "$role" orbit-binary "$phase_start"
    fi
    phase_start="$(now_ms)"
    authorize_ssh "$name"
    record_timing "$role" ssh-authorize "$phase_start"
    phase_start="$(now_ms)"
    wait_for_ssh "$name"
    record_timing "$role" ssh-ready "$phase_start"
}

%s

STATUS=0;
%s
%s
%s
exit "$STATUS";
BASH,
            escapeshellarg($this->host->config->bootstrapUser),
            escapeshellarg((string) $this->remoteBundleDir),
            escapeshellarg((string) $this->remoteOrbitBinaryBundleDir),
            escapeshellarg((string) $this->remoteSourcePath),
            escapeshellarg($key->privateKeyPath),
            escapeshellarg($key->publicKeyPath),
            $this->host->config->timeoutSeconds,
            escapeshellarg($sourceRuntimeInstallCommand),
            self::PreparedSourceMountPath,
            self::PreparedSourceMountPath,
            implode("\n", $caseLines),
            implode("\n", $startLines),
            implode("\n", $statusLines),
            implode("\n", $waitLines),
            implode("\n", $echoLines),
        );
        $scriptPath = '/tmp/orbit-e2e-prepared-downstream-roles.sh';
        $scriptPathArgument = escapeshellarg($scriptPath);

        $writeResult = $this->host->run(
            "cat > {$scriptPathArgument} <<'BASH'\n{$script}\nBASH\nchmod 755 {$scriptPathArgument}",
            timeoutSeconds: 30,
        );

        if (! $writeResult->successful()) {
            throw new RuntimeException("Could not write prepared downstream role script: {$writeResult->errorOutput()}");
        }

        $result = $this->host->run($scriptPathArgument, timeoutSeconds: max(900, $this->host->config->timeoutSeconds * 4));
        $output = $result->output()."\n".$result->errorOutput();
        $this->recordPreparedRoleTimings($output, $roles, 'prepare', $timerPrefix);
        $statuses = $this->parsePreparedRoleStatuses($output, $roles, 'prepare');
        $failedRoles = array_keys(array_filter(
            $statuses,
            fn (int $status): bool => $status !== 0,
        ));

        if ($failedRoles !== []) {
            throw new RuntimeException('Could not prepare prepared downstream roles: '.implode(', ', $failedRoles));
        }

        $instances = [];

        foreach ($roles as $role) {
            $instances[$role] = new IncusInstance($this->host, IncusTopologyTemplate::templateName($templateKind, $role));
        }

        return $instances;
    }

    private function useWireGuardGatewayUrl(IncusInstance $operator, SshKeyPair $key): void
    {
        $gatewayUrl = var_export('https://'.self::GatewayWireGuardIp, true);
        $gatewayIp = var_export(self::GatewayWireGuardIp, true);
        $database = var_export($this->operatorGatewayDatabasePath(), true);

        $php = <<<PHP
\$pdo = new PDO('sqlite:'.{$database});
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec('PRAGMA busy_timeout = 5000');
\$now = gmdate('Y-m-d H:i:s');
\$id = \$pdo->query('SELECT id FROM local_gateway_settings ORDER BY id LIMIT 1')->fetchColumn();

if (\$id === false) {
    \$statement = \$pdo->prepare('INSERT INTO local_gateway_settings (gateway_url, gateway_wg_ip, created_at, updated_at) VALUES (:gateway_url, :gateway_wg_ip, :now, :now)');
    \$statement->execute(['gateway_url' => {$gatewayUrl}, 'gateway_wg_ip' => {$gatewayIp}, 'now' => \$now]);
} else {
    \$statement = \$pdo->prepare('UPDATE local_gateway_settings SET gateway_url = :gateway_url, gateway_wg_ip = :gateway_wg_ip, updated_at = :now WHERE id = :id');
    \$statement->execute(['gateway_url' => {$gatewayUrl}, 'gateway_wg_ip' => {$gatewayIp}, 'now' => \$now, 'id' => \$id]);
}
PHP;

        $this->runOperatorPhp($operator, $key, $php);
    }

    private function writeOperatorCliConfig(IncusInstance $operator, SshKeyPair $key, string $gatewayUrl = 'https://10.6.0.2', ?string $caPemPath = null, ?string $caSha256 = null): void
    {
        $operatorUser = $this->host->config->operatorUser;
        $configDir = '/home/'.$operatorUser.'/.config/orbit';
        $configPath = $configDir.'/config.json';
        $jsonBody = $this->cliJsonConfigBody($gatewayUrl, $caPemPath, $caSha256);

        $command = implode(' && ', [
            sprintf('mkdir -p %s', escapeshellarg($configDir)),
            sprintf('chmod 0700 %s', escapeshellarg($configDir)),
            sprintf('printf %%s %s > %s', escapeshellarg($jsonBody), escapeshellarg($configPath)),
            sprintf('chmod 0600 %s', escapeshellarg($configPath)),
        ]);

        E2ECommand::ssh(
            $operator,
            $operatorUser,
            $key,
            $command,
            timeoutSeconds: 60,
        );
    }

    private function cliJsonConfigBody(string $gatewayUrl, ?string $caPemPath = null, ?string $caSha256 = null): string
    {
        return json_encode([
            'schema_version' => 1,
            'active_gateway' => 'default',
            'gateways' => [
                'default' => [
                    'url' => $gatewayUrl,
                    'wireguard_ip' => self::GatewayWireGuardIp,
                    'ca_pem_path' => $caPemPath,
                    'ca_sha256' => $caSha256,
                    'ca_fingerprint' => null,
                    'timeout' => 30,
                    'self_mode' => 'wireguard_https',
                ],
            ],
            'defaults' => ['node' => null, 'profile' => null],
            'meta' => ['imported_from' => 'incus-e2e-topology', 'imported_at' => date(DATE_ATOM)],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function runAppNodeNew(
        IncusInstance $operator,
        SshKeyPair $key,
        string $name,
        string $host,
        string $environment,
        ?string $tld = null,
        bool $withIngress = false,
    ): void {
        $roles = $environment === 'development' ? 'app-dev' : 'app-prod';

        if ($withIngress) {
            $roles .= ',ingress';
        }

        $parts = [
            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && orbit node:new',
            escapeshellarg($name),
            '--roles='.$roles,
            '--host='.escapeshellarg($host),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--json',
        ];

        if ($tld !== null) {
            $parts[] = '--tld='.escapeshellarg($tld);
        }

        E2ECommand::ssh(
            $operator,
            $this->host->config->operatorUser,
            $key,
            implode(' ', $parts),
            timeoutSeconds: 900,
        );

        E2EGatewayApi::waitForGatewayApi($operator, $this->host->config->operatorUser, $key);
    }

    private function runAgentNodeNew(
        IncusInstance $operator,
        SshKeyPair $key,
        string $name,
        string $host,
    ): void {
        $parts = [
            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && orbit node:new',
            escapeshellarg($name),
            '--roles=agent',
            '--host='.escapeshellarg($host),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--json',
        ];

        E2ECommand::ssh(
            $operator,
            $this->host->config->operatorUser,
            $key,
            implode(' ', $parts),
            timeoutSeconds: 900,
        );

        E2EGatewayApi::waitForGatewayApi($operator, $this->host->config->operatorUser, $key);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, int>
     */
    private function runPreparedDownstreamBakeInParallel(
        IncusInstance $gateway,
        string $devHost,
        string $prodHost,
        string $agentHost,
        array $roles = ['dev', 'prod', 'agent'],
        ?callable $beforeDevelopmentBake = null,
        string $timerPrefix = 'prepared.downstream.bake',
    ): array {
        $roles = array_values(array_intersect(['dev', 'prod', 'agent'], $roles));

        if ($roles === []) {
            return [];
        }

        $devCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-dev-1'),
            '--role=app-dev',
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg(self::AppDevelopmentRuntimeUser),
            '--tld='.escapeshellarg('test'),
        ]));
        $prodIngressCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-ingress-node',
            escapeshellarg('app-prod-1'),
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
        ]));
        $prodAppCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-prod-1'),
            '--role=app-prod',
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--ingress-node='.escapeshellarg('app-prod-1'),
        ]));
        $prodCommand = "{$prodIngressCommand} && {$prodAppCommand}";
        $agentCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-agent-node',
            escapeshellarg('agent-1'),
            '--host='.escapeshellarg($agentHost),
            '--wireguard-address='.escapeshellarg(self::AgentWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--tld='.escapeshellarg('agent'),
        ]));
        $commands = array_filter([
            'dev' => $devCommand,
            'prod' => $prodCommand,
            'agent' => $agentCommand,
        ], fn (string $role): bool => in_array($role, $roles, true), ARRAY_FILTER_USE_KEY);
        $labels = [
            'dev' => 'app-dev',
            'prod' => 'app-prod',
            'agent' => 'agent',
        ];
        $startLines = [];
        $developmentStartLines = [];
        $statusLines = [];
        $waitLines = [];
        $developmentWaitLines = [];
        $echoLines = [];
        $developmentEchoLines = [];
        $deferDevelopmentBake = $beforeDevelopmentBake !== null && in_array('dev', $roles, true);

        foreach ($commands as $role => $command) {
            $suffix = strtoupper(str_replace('-', '_', $role));
            $pid = "PID_BAKE_{$suffix}";
            $status = "STATUS_{$suffix}";
            $logPath = "/tmp/orbit-e2e-bake-{$role}.log";
            $startLine = sprintf(
                '(BAKE_START_MS="$(now_ms)"; (%s); BAKE_STATUS=$?; BAKE_END_MS="$(now_ms)"; echo "__orbit_bake_timing %s total $((BAKE_END_MS - BAKE_START_MS))" | tee -a "$TIMING_FILE"; exit "$BAKE_STATUS") > %s 2>&1 & %s=$!;',
                $command,
                $role,
                escapeshellarg($logPath),
                $pid,
            );
            $waitLine = sprintf(
                'wait "$%1$s" || { %2$s=$?; echo %3$s >&2; cat %4$s >&2 || true; if [ "$STATUS" -eq 0 ]; then STATUS=$%2$s; fi; };',
                $pid,
                $status,
                escapeshellarg("bake {$labels[$role]} failed"),
                escapeshellarg($logPath),
            );
            $echoLine = sprintf('echo "__orbit_bake_status %s $%s";', $role, $status);

            $statusLines[] = "{$status}=0;";

            if ($deferDevelopmentBake && $role === 'dev') {
                $developmentStartLines[] = $startLine;
                $developmentWaitLines[] = $waitLine;
                $developmentEchoLines[] = $echoLine;

                continue;
            }

            $startLines[] = $startLine;
            $waitLines[] = $waitLine;
            $echoLines[] = $echoLine;
        }

        $devReadyMarkerPath = '/tmp/orbit-e2e-prepared-dev-ready';
        $timingPath = '/tmp/orbit-e2e-prepared-bake.timing';
        $developmentLines = [];

        if ($deferDevelopmentBake) {
            $developmentLines = [
                'DEV_READY_MARKER='.escapeshellarg($devReadyMarkerPath).';',
                'DEV_READY_DEADLINE=$(($(date +%s) + 900));',
                'while [ ! -f "$DEV_READY_MARKER" ]; do',
                '    if [ "$(date +%s)" -ge "$DEV_READY_DEADLINE" ]; then',
                '        STATUS_DEV=1;',
                '        echo '.escapeshellarg('timed out waiting for app-dev runtime prerequisites before bake').' >&2;',
                '        if [ "$STATUS" -eq 0 ]; then STATUS=$STATUS_DEV; fi;',
                '        break;',
                '    fi;',
                '    sleep 1;',
                'done;',
                'if [ "$STATUS_DEV" -eq 0 ]; then',
                ...array_map(fn (string $line): string => "    {$line}", $developmentStartLines),
                ...array_map(fn (string $line): string => "    {$line}", $developmentWaitLines),
                'fi;',
                ...$developmentEchoLines,
            ];
        }

        $script = implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail;',
            'cd /home/orbit/orbit;',
            'now_ms() { if command -v python3 >/dev/null 2>&1; then python3 -c '.escapeshellarg('import time; print(int(time.time() * 1000))').'; else echo "$(($(date +%s) * 1000))"; fi; }',
            'TIMING_FILE='.escapeshellarg($timingPath).';',
            ': > "$TIMING_FILE";',
            ...$startLines,
            '',
            'STATUS=0;',
            ...$statusLines,
            ...$developmentLines,
            ...$waitLines,
            ...$echoLines,
            'cat "$TIMING_FILE" 2>/dev/null || true;',
            'exit "$STATUS";',
        ]);
        $scriptPath = '/tmp/orbit-e2e-prepared-bake.sh';
        $scriptPathArgument = escapeshellarg($scriptPath);

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPathArgument} <<'BASH'\n{$script}\nBASH\nchmod 755 {$scriptPathArgument}\nchown orbit:orbit {$scriptPathArgument}",
            'Could not write prepared downstream bake script',
            timeoutSeconds: 30,
        );

        if ($deferDevelopmentBake) {
            $donePath = '/tmp/orbit-e2e-prepared-bake.done';
            $exitPath = '/tmp/orbit-e2e-prepared-bake.exit';
            $pidPath = '/tmp/orbit-e2e-prepared-bake.pid';
            $outputPath = '/tmp/orbit-e2e-prepared-bake.out';
            $errorPath = '/tmp/orbit-e2e-prepared-bake.err';
            $runner = sprintf(
                'set +e; bash %s > %s 2> %s; code=$?; echo "$code" > %s; touch %s; exit "$code"',
                $scriptPathArgument,
                escapeshellarg($outputPath),
                escapeshellarg($errorPath),
                escapeshellarg($exitPath),
                escapeshellarg($donePath),
            );

            E2ECommand::exec(
                $gateway,
                sprintf(
                    'rm -f %s %s %s %s %s %s %s; nohup sh -lc %s >/dev/null 2>&1 & echo $! > %s',
                    escapeshellarg($donePath),
                    escapeshellarg($exitPath),
                    escapeshellarg($pidPath),
                    escapeshellarg($outputPath),
                    escapeshellarg($errorPath),
                    escapeshellarg($devReadyMarkerPath),
                    escapeshellarg($timingPath),
                    escapeshellarg($runner),
                    escapeshellarg($pidPath),
                ),
                'Could not start prepared downstream bake script',
                timeoutSeconds: 30,
            );

            $beforeDevelopmentBake();

            E2ECommand::exec(
                $gateway,
                'touch '.escapeshellarg($devReadyMarkerPath),
                'Could not release prepared app-dev bake after runtime prerequisites',
                timeoutSeconds: 30,
            );

            $result = $gateway->exec(sprintf(
                <<<'BASH'
deadline=$(($(date +%%s) + 900))
while [ ! -f %s ]; do
    if [ "$(date +%%s)" -ge "$deadline" ]; then
        echo "prepared downstream bake did not finish" >&2
        cat %s >&2 2>/dev/null || true
        cat %s >&2 2>/dev/null || true
        exit 1
    fi

    sleep 2
done

cat %s 2>/dev/null || true
cat %s >&2 2>/dev/null || true
exit "$(cat %s 2>/dev/null || echo 1)"
BASH,
                escapeshellarg($donePath),
                escapeshellarg($outputPath),
                escapeshellarg($errorPath),
                escapeshellarg($outputPath),
                escapeshellarg($errorPath),
                escapeshellarg($exitPath),
            ), timeoutSeconds: 930);

            $this->lastPreparedBakeOutput = trim($result->output()."\n".$result->errorOutput());
            $this->recordPreparedRoleTimings($this->lastPreparedBakeOutput, $roles, 'bake', $timerPrefix);

            return $this->parsePreparedBakeStatuses($this->lastPreparedBakeOutput, $roles);
        }

        $result = $gateway->exec(
            'bash '.$scriptPathArgument,
            timeoutSeconds: 900,
        );

        $this->lastPreparedBakeOutput = trim($result->output()."\n".$result->errorOutput());
        $this->recordPreparedRoleTimings($this->lastPreparedBakeOutput, $roles, 'bake', $timerPrefix);
        $statuses = $this->parsePreparedBakeStatuses($this->lastPreparedBakeOutput, $roles);

        if ($result->successful()) {
            return $statuses;
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, int>
     */
    private function runPreparedDownstreamAndWebSocketBakeInParallel(
        IncusInstance $gateway,
        string $devHost,
        string $prodHost,
        string $agentHost,
        array $roles = ['dev', 'prod', 'agent'],
        string $timerPrefix = 'prepared-websocket.downstream.bake',
        ?callable $beforeDevelopmentBake = null,
        ?callable $afterDevelopmentBake = null,
    ): array {
        $roles = array_values(array_intersect(['dev', 'prod', 'agent'], $roles));

        if ($roles === []) {
            return [];
        }

        $devCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-dev-1'),
            '--role=app-dev',
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg(self::AppDevelopmentRuntimeUser),
            '--tld='.escapeshellarg('test'),
        ]));
        $prodIngressCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-ingress-node',
            escapeshellarg('app-prod-1'),
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
        ]));
        $prodAppCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-prod-1'),
            '--role=app-prod',
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--ingress-node='.escapeshellarg('app-prod-1'),
        ]));
        $prodCommand = "{$prodIngressCommand} && {$prodAppCommand}";
        $agentCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-agent-node',
            escapeshellarg('agent-1'),
            '--host='.escapeshellarg($agentHost),
            '--wireguard-address='.escapeshellarg(self::AgentWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--tld='.escapeshellarg('agent'),
        ]));
        $webSocketCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-websocket-node',
            escapeshellarg('app-dev-1'),
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--redis-node='.escapeshellarg('app-dev-1'),
            '--converge-runtime',
        ]));
        $commands = array_filter([
            'dev' => $devCommand,
            'prod' => $prodCommand,
            'agent' => $agentCommand,
        ], fn (string $role): bool => in_array($role, $roles, true), ARRAY_FILTER_USE_KEY);
        $labels = [
            'dev' => 'app-dev',
            'prod' => 'app-prod',
            'agent' => 'agent',
        ];
        $startLines = [];
        $developmentStartLines = [];
        $statusLines = [];
        $waitDevLines = [];
        $waitSiblingLines = [];

        foreach ($commands as $role => $command) {
            $suffix = strtoupper(str_replace('-', '_', $role));
            $pid = "PID_BAKE_{$suffix}";
            $status = "STATUS_{$suffix}";
            $logPath = "/tmp/orbit-e2e-bake-{$role}.log";
            $waitLine = sprintf(
                'wait "$%1$s" || { %2$s=$?; echo %3$s >&2; cat %4$s >&2 || true; if [ "$STATUS" -eq 0 ]; then STATUS=$%2$s; fi; };',
                $pid,
                $status,
                escapeshellarg("bake {$labels[$role]} failed"),
                escapeshellarg($logPath),
            );

            $startLine = sprintf(
                '(BAKE_START_MS="$(now_ms)"; (%s); BAKE_STATUS=$?; BAKE_END_MS="$(now_ms)"; echo "__orbit_bake_timing %s total $((BAKE_END_MS - BAKE_START_MS))" | tee -a "$STATUS_FILE"; exit "$BAKE_STATUS") > %s 2>&1 & %s=$!;',
                $command,
                $role,
                escapeshellarg($logPath),
                $pid,
            );
            $statusLines[] = "{$status}=0;";
            $recordLine = sprintf('record_status %s "$%s";', escapeshellarg($role), $status);

            if ($role === 'dev') {
                $developmentStartLines[] = $startLine;
                $waitDevLines[] = $waitLine;
                $waitDevLines[] = $recordLine;

                continue;
            }

            $startLines[] = $startLine;
            $waitSiblingLines[] = $waitLine;
            $waitSiblingLines[] = $recordLine;
        }

        $webSocketLines = [];
        $developmentLines = [];
        $rolesWithWebSocket = $roles;
        $deferDevelopmentBake = $beforeDevelopmentBake !== null;

        if (in_array('dev', $roles, true)) {
            $rolesWithWebSocket[] = 'websocket';
            $developmentLines = $deferDevelopmentBake ? [
                'DEV_READY_DEADLINE=$(($(date +%s) + 900));',
                'while [ ! -f "$DEV_READY_MARKER" ]; do',
                '    if [ "$(date +%s)" -ge "$DEV_READY_DEADLINE" ]; then',
                '        STATUS_DEV=1;',
                '        echo '.escapeshellarg('timed out waiting for app-dev runtime prerequisites before bake').' >&2;',
                '        if [ "$STATUS" -eq 0 ]; then STATUS=$STATUS_DEV; fi;',
                '        break;',
                '    fi;',
                '    sleep 1;',
                'done;',
                'if [ "$STATUS_DEV" -eq 0 ]; then',
                ...array_map(fn (string $line): string => "    {$line}", $developmentStartLines),
                ...array_map(fn (string $line): string => "    {$line}", $waitDevLines),
                'else',
                '    record_status dev "$STATUS_DEV";',
                'fi;',
            ] : [
                ...$developmentStartLines,
                ...$waitDevLines,
            ];
            $webSocketLines = [
                'STATUS_WEBSOCKET=0;',
                'if [ "$STATUS_DEV" -eq 0 ]; then',
                '    SEED_DEADLINE=$(($(date +%s) + 900));',
                '    until [ -f "$SEED_MARKER" ]; do',
                '        if [ "$(date +%s)" -ge "$SEED_DEADLINE" ]; then',
                '            STATUS_WEBSOCKET=1;',
                '            echo '.escapeshellarg('timed out waiting for app-dev registry seed before websocket bake').' >&2;',
                '            if [ "$STATUS" -eq 0 ]; then STATUS=$STATUS_WEBSOCKET; fi;',
                '            break;',
                '        fi;',
                '        sleep 1;',
                '    done;',
                '    if [ "$STATUS_WEBSOCKET" -eq 0 ]; then',
                sprintf('        (BAKE_START_MS="$(now_ms)"; (%s); BAKE_STATUS=$?; BAKE_END_MS="$(now_ms)"; echo "__orbit_bake_timing websocket total $((BAKE_END_MS - BAKE_START_MS))" | tee -a "$STATUS_FILE"; exit "$BAKE_STATUS") > %s 2>&1 & PID_BAKE_WEBSOCKET=$!;', $webSocketCommand, escapeshellarg('/tmp/orbit-e2e-bake-websocket.log')),
                '        wait "$PID_BAKE_WEBSOCKET" || { STATUS_WEBSOCKET=$?; echo '.escapeshellarg('bake websocket failed').' >&2; cat '.escapeshellarg('/tmp/orbit-e2e-bake-websocket.log').' >&2 || true; if [ "$STATUS" -eq 0 ]; then STATUS=$STATUS_WEBSOCKET; fi; };',
                '    fi;',
                'else',
                '    STATUS_WEBSOCKET=1;',
                'fi;',
                'record_status websocket "$STATUS_WEBSOCKET";',
            ];
        }

        $statusPath = '/tmp/orbit-e2e-prepared-bake.status';
        $donePath = '/tmp/orbit-e2e-prepared-bake.done';
        $exitPath = '/tmp/orbit-e2e-prepared-bake.exit';
        $pidPath = '/tmp/orbit-e2e-prepared-bake.pid';
        $outputPath = '/tmp/orbit-e2e-prepared-bake.out';
        $errorPath = '/tmp/orbit-e2e-prepared-bake.err';
        $seedMarkerPath = '/tmp/orbit-e2e-dev-registry-seeded';
        $devReadyMarkerPath = '/tmp/orbit-e2e-prepared-dev-ready';

        $script = implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail;',
            'cd /home/orbit/orbit;',
            'STATUS_FILE='.escapeshellarg($statusPath).';',
            'SEED_MARKER='.escapeshellarg($seedMarkerPath).';',
            'DEV_READY_MARKER='.escapeshellarg($devReadyMarkerPath).';',
            ': > "$STATUS_FILE";',
            'now_ms() { if command -v python3 >/dev/null 2>&1; then python3 -c '.escapeshellarg('import time; print(int(time.time() * 1000))').'; else echo "$(($(date +%s) * 1000))"; fi; }',
            'record_status() { echo "__orbit_bake_status $1 $2" | tee -a "$STATUS_FILE"; }',
            ...$startLines,
            '',
            'STATUS=0;',
            ...$statusLines,
            ...$developmentLines,
            ...$webSocketLines,
            ...$waitSiblingLines,
            'exit "$STATUS";',
        ]);
        $scriptPath = '/tmp/orbit-e2e-prepared-bake.sh';
        $scriptPathArgument = escapeshellarg($scriptPath);

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPathArgument} <<'BASH'\n{$script}\nBASH\nchmod 755 {$scriptPathArgument}\nchown orbit:orbit {$scriptPathArgument}",
            'Could not write prepared downstream websocket bake script',
            timeoutSeconds: 30,
        );

        $runner = sprintf(
            'set +e; bash %s > %s 2> %s; code=$?; echo "$code" > %s; touch %s; exit "$code"',
            $scriptPathArgument,
            escapeshellarg($outputPath),
            escapeshellarg($errorPath),
            escapeshellarg($exitPath),
            escapeshellarg($donePath),
        );
        E2ECommand::exec(
            $gateway,
            sprintf(
                'rm -f %s %s %s %s %s %s %s %s; nohup sh -lc %s >/dev/null 2>&1 & echo $! > %s',
                escapeshellarg($statusPath),
                escapeshellarg($donePath),
                escapeshellarg($exitPath),
                escapeshellarg($pidPath),
                escapeshellarg($outputPath),
                escapeshellarg($errorPath),
                escapeshellarg($seedMarkerPath),
                escapeshellarg($devReadyMarkerPath),
                escapeshellarg($runner),
                escapeshellarg($pidPath),
            ),
            'Could not start prepared downstream websocket bake script',
            timeoutSeconds: 30,
        );

        if (in_array('dev', $roles, true)) {
            if ($beforeDevelopmentBake !== null) {
                $beforeDevelopmentBake();

                E2ECommand::exec(
                    $gateway,
                    'touch '.escapeshellarg($devReadyMarkerPath),
                    'Could not release prepared app-dev bake after runtime prerequisites',
                    timeoutSeconds: 30,
                );
            }

            $developmentBakeStatus = $this->waitForPreparedBakeRoleStatus($gateway, 'dev', $statusPath, $donePath, $outputPath, $errorPath);

            if ($developmentBakeStatus === 0) {
                if ($afterDevelopmentBake !== null) {
                    $afterDevelopmentBake();
                }

                E2ECommand::exec(
                    $gateway,
                    'touch '.escapeshellarg($seedMarkerPath),
                    'Could not release prepared websocket bake after app-dev seed',
                    timeoutSeconds: 30,
                );
            }
        }

        $result = $this->timer->measure(
            'prepared-websocket.websocket.bake',
            fn (): ProcessResult => $gateway->exec(sprintf(
                <<<'BASH'
deadline=$(($(date +%%s) + 900))
while [ ! -f %s ]; do
    if [ "$(date +%%s)" -ge "$deadline" ]; then
        echo "prepared downstream websocket bake did not finish" >&2
        cat %s >&2 2>/dev/null || true
        cat %s >&2 2>/dev/null || true
        exit 1
    fi

    sleep 2
done

cat %s 2>/dev/null || true
cat %s 2>/dev/null || true
cat %s >&2 2>/dev/null || true
exit "$(cat %s 2>/dev/null || echo 1)"
BASH,
                escapeshellarg($donePath),
                escapeshellarg($outputPath),
                escapeshellarg($errorPath),
                escapeshellarg($statusPath),
                escapeshellarg($outputPath),
                escapeshellarg($errorPath),
                escapeshellarg($exitPath),
            ), timeoutSeconds: 930),
        );

        $this->lastPreparedBakeOutput = trim($result->output()."\n".$result->errorOutput());
        $this->recordPreparedRoleTimings($this->lastPreparedBakeOutput, $rolesWithWebSocket, 'bake', $timerPrefix);

        return $this->parsePreparedBakeStatuses($this->lastPreparedBakeOutput, $rolesWithWebSocket);
    }

    private function waitForPreparedBakeRoleStatus(
        IncusInstance $gateway,
        string $role,
        string $statusPath,
        string $donePath,
        string $outputPath,
        string $errorPath,
    ): int {
        $result = $gateway->exec(sprintf(
            <<<'BASH'
deadline=$(($(date +%%s) + 900))
while true; do
    if grep -E %s %s 2>/dev/null; then
        exit 0
    fi

    if [ -f %s ]; then
        cat %s 2>/dev/null || true
        cat %s >&2 2>/dev/null || true
        exit 1
    fi

    if [ "$(date +%%s)" -ge "$deadline" ]; then
        echo "timed out waiting for prepared bake status [%s]" >&2
        cat %s >&2 2>/dev/null || true
        cat %s >&2 2>/dev/null || true
        exit 1
    fi

    sleep 1
done
BASH,
            escapeshellarg("^__orbit_bake_status {$role} "),
            escapeshellarg($statusPath),
            escapeshellarg($donePath),
            escapeshellarg($outputPath),
            escapeshellarg($errorPath),
            $role,
            escapeshellarg($outputPath),
            escapeshellarg($errorPath),
        ), timeoutSeconds: 930);

        $statuses = $this->parsePreparedBakeStatuses($result->output()."\n".$result->errorOutput(), [$role]);

        return $statuses[$role] ?? 1;
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, int>
     */
    private function parsePreparedBakeStatuses(string $output, array $roles): array
    {
        return $this->parsePreparedRoleStatuses($output, $roles, 'bake');
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, int>
     */
    private function parsePreparedRoleStatuses(string $output, array $roles, string $phase): array
    {
        $statuses = array_fill_keys($roles, 1);

        if (preg_match_all('/__orbit_'.$phase.'_status\s+([a-z-]+)\s+(\d+)/', $output, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $role = $match[1];

                if (! array_key_exists($role, $statuses)) {
                    continue;
                }

                $statuses[$role] = (int) $match[2];
            }
        }

        return $statuses;
    }

    /**
     * @param  list<string>  $roles
     */
    private function recordPreparedRoleTimings(string $output, array $roles, string $phase, string $timerPrefix): void
    {
        $allowedRoles = array_fill_keys($roles, true);
        $pattern = '/__orbit_'.$phase.'_timing\s+([a-z-]+)\s+([a-z-]+)\s+(\d+)/';

        if (preg_match_all($pattern, $output, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($matches as $match) {
            $role = $match[1];

            if (! array_key_exists($role, $allowedRoles)) {
                continue;
            }

            $step = $match[2];
            $milliseconds = (int) $match[3];

            $this->timer->recordExternal("{$timerPrefix}.{$role}.{$step}", $milliseconds / 1000);
        }
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, int>  $statuses
     */
    private function checkpointSuccessfulPreparedRolesOrFail(E2ETopologyKind $kind, array $instances, array $statuses): void
    {
        $failedRoles = array_keys(array_filter(
            $statuses,
            fn (int $status): bool => $status !== 0,
        ));

        if ($failedRoles === []) {
            return;
        }

        $successfulRoles = array_keys(array_filter(
            $statuses,
            fn (int $status): bool => $status === 0,
        ));
        $checkpointRoles = array_values(array_unique(['operator', 'gateway', ...$successfulRoles]));
        $checkpointInstances = array_intersect_key($instances, array_flip($checkpointRoles));

        if ($checkpointInstances !== []) {
            $manifest = $this->finalizeInstances($kind, $checkpointInstances);
            $this->recordCheckpointManifest($kind, $manifest, complete: false);
        }

        $details = $this->lastPreparedBakeOutput !== ''
            ? "\n\n".$this->lastPreparedBakeOutput
            : '';

        throw new RuntimeException('Could not bake prepared downstream roles: '.implode(', ', $failedRoles).'. Successful sibling checkpoints were retained when possible.'.$details);
    }

    private function runPreparedDedicatedIngressBakeInParallel(
        IncusInstance $gateway,
        string $devHost,
        string $prodHost,
        string $ingressHost,
    ): void {
        $devCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-dev-1'),
            '--role=app-dev',
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg(self::AppDevelopmentRuntimeUser),
            '--tld='.escapeshellarg('test'),
        ]));
        $ingressCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-ingress-node',
            escapeshellarg('edge-1'),
            '--host='.escapeshellarg($ingressHost),
            '--wireguard-address='.escapeshellarg(self::IngressWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
        ]));
        $prodCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-prod-1'),
            '--role=app-prod',
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--ingress-node='.escapeshellarg('edge-1'),
        ]));
        $script = <<<BASH
set -euo pipefail;
cd /home/orbit/orbit;
({$devCommand}) > /tmp/orbit-e2e-bake-dev.log 2>&1 & PID_BAKE_DEV=\$!;
({$ingressCommand}) > /tmp/orbit-e2e-bake-ingress.log 2>&1 & PID_BAKE_INGRESS=\$!;

STATUS=0;
wait "\$PID_BAKE_DEV" || { CODE=\$?; echo "bake app-dev failed" >&2; cat /tmp/orbit-e2e-bake-dev.log >&2 || true; if [ "\$STATUS" -eq 0 ]; then STATUS=\$CODE; fi; };
wait "\$PID_BAKE_INGRESS" || { CODE=\$?; echo "bake ingress failed" >&2; cat /tmp/orbit-e2e-bake-ingress.log >&2 || true; if [ "\$STATUS" -eq 0 ]; then STATUS=\$CODE; fi; };
if [ "\$STATUS" -ne 0 ]; then exit "\$STATUS"; fi;

({$prodCommand}) > /tmp/orbit-e2e-bake-prod.log 2>&1 & PID_BAKE_PROD=\$!;
wait "\$PID_BAKE_PROD" || { CODE=\$?; echo "bake app-prod failed" >&2; cat /tmp/orbit-e2e-bake-prod.log >&2 || true; if [ "\$STATUS" -eq 0 ]; then STATUS=\$CODE; fi; };
exit "\$STATUS";
BASH;
        $scriptPath = '/tmp/orbit-e2e-prepared-dedicated-ingress-bake.sh';
        $scriptPathArgument = escapeshellarg($scriptPath);

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPathArgument} <<'BASH'\n{$script}\nBASH\nchmod 755 {$scriptPathArgument}\nchown orbit:orbit {$scriptPathArgument}",
            'Could not write prepared dedicated ingress bake script',
            timeoutSeconds: 30,
        );
        E2ECommand::exec(
            $gateway,
            $scriptPathArgument,
            'Could not bake prepared dedicated ingress nodes in parallel',
            timeoutSeconds: 900,
        );
    }

    private function waitForGatewayWireGuard(IncusInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            'deadline=$((SECONDS+180)); wg_easy_public_key=""; until test -f /home/orbit/.wg-easy/wg-easy.db && docker exec wg-easy ip link show wg0 >/dev/null 2>&1 && wg_easy_public_key="$(docker exec wg-easy wg show wg0 public-key 2>/dev/null || true)" && test -n "$wg_easy_public_key"; do if [ "$SECONDS" -ge "$deadline" ]; then docker ps -a; docker logs --tail=120 wg-easy 2>&1 || true; exit 1; fi; sleep 2; done',
            "wg-easy did not become ready on {$gateway->name()}",
            timeoutSeconds: 210,
        );
    }

    private function bootstrapGatewayLocal(IncusInstance $gateway, string $publicHost): void
    {
        E2ECommand::gatewayArtisan(
            $gateway,
            sprintf(
                'orbit:internal:bootstrap-gateway-local gateway %s --public-host=%s --skip-gateway-service-install',
                escapeshellarg(self::GatewayWireGuardIp),
                escapeshellarg($publicHost),
            ),
            'Could not bootstrap local gateway identity',
            timeoutSeconds: 120,
        );
    }

    private function retargetOperator(IncusInstance $operator, string $gatewayPublicEndpoint, SshKeyPair $key): void
    {
        $gatewayIpValue = var_export(self::GatewayWireGuardIp, true);
        $gatewayPublicEndpointValue = var_export($gatewayPublicEndpoint, true);
        $database = var_export($this->operatorGatewayDatabasePath(), true);

        $php = <<<PHP
\$pdo = new PDO('sqlite:'.{$database});
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec('PRAGMA busy_timeout = 5000');
\$now = gmdate('Y-m-d H:i:s');

\$statement = \$pdo->prepare(<<<'SQL'
INSERT INTO nodes (name, tld, platform, host, wireguard_address, gateway_endpoint, user, orbit_path, status, created_at, updated_at)
VALUES (:name, NULL, :platform, :host, :wireguard_address, NULL, :user, :orbit_path, :status, :now, :now)
ON CONFLICT(name) DO UPDATE SET
    tld = excluded.tld,
    platform = excluded.platform,
    host = excluded.host,
    wireguard_address = excluded.wireguard_address,
    gateway_endpoint = excluded.gateway_endpoint,
    user = excluded.user,
    orbit_path = excluded.orbit_path,
    status = excluded.status,
    updated_at = excluded.updated_at
SQL);
\$statement->execute([
    'name' => 'gateway',
    'platform' => 'unknown',
    'host' => {$gatewayIpValue},
    'wireguard_address' => {$gatewayIpValue},
    'user' => 'orbit',
    'orbit_path' => '/home/orbit/orbit',
    'status' => 'active',
    'now' => \$now,
]);

\$gatewayId = (int) \$pdo->query("SELECT id FROM nodes WHERE name = 'gateway'")->fetchColumn();
\$roleStatement = \$pdo->prepare(<<<'SQL'
INSERT INTO node_role (node_id, role, status, settings, last_error, converged_at, created_at, updated_at)
VALUES (:node_id, :role, :status, :settings, NULL, :now, :now, :now)
ON CONFLICT(node_id, role) DO UPDATE SET
    status = excluded.status,
    settings = excluded.settings,
    last_error = excluded.last_error,
    converged_at = excluded.converged_at,
    updated_at = excluded.updated_at
SQL);
\$roleStatement->execute([
    'node_id' => \$gatewayId,
    'role' => 'gateway',
    'status' => 'active',
    'settings' => json_encode([], JSON_THROW_ON_ERROR),
    'now' => \$now,
]);
\$roleStatement->execute([
    'node_id' => \$gatewayId,
    'role' => 'vpn',
    'status' => 'active',
    'settings' => json_encode([
        'public_endpoint' => {$gatewayPublicEndpointValue},
        'wireguard_cidr' => '10.6.0.0/24',
        'wireguard_port' => 51820,
        'dns_ip' => '10.6.0.1',
    ], JSON_THROW_ON_ERROR),
    'now' => \$now,
]);
PHP;

        $this->runOperatorPhp($operator, $key, $php, timeoutSeconds: 120);
    }

    private function trustGatewayCaOnOperator(IncusInstance $operator, IncusInstance $gateway, SshKeyPair $key): void
    {
        $rootCa = E2ECommand::gatewayArtisan(
            $gateway,
            'tinker --execute='.escapeshellarg('echo app(\App\Services\Ca\OrbitCaService::class)->rootCert();'),
            'Could not read gateway root CA',
            timeoutSeconds: 60,
        )->output();

        $rootCaValue = var_export($rootCa, true);
        $gatewayUrlValue = var_export('https://'.self::GatewayWireGuardIp, true);
        $gatewayIpValue = var_export(self::GatewayWireGuardIp, true);
        $database = var_export($this->operatorGatewayDatabasePath(), true);
        $pemPathValue = var_export('/home/'.$this->host->config->operatorUser.'/.config/orbit/gateway-ca/orbit.crt', true);

        $php = <<<PHP
\$pdo = new PDO('sqlite:'.{$database});
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$pdo->exec('PRAGMA busy_timeout = 5000');
\$now = gmdate('Y-m-d H:i:s');
\$rootCa = {$rootCaValue};
\$pemPath = {$pemPathValue};

if (! is_dir(dirname(\$pemPath))) {
    mkdir(dirname(\$pemPath), 0700, true);
}

file_put_contents(\$pemPath, \$rootCa);
chmod(\$pemPath, 0600);

\$id = \$pdo->query('SELECT id FROM local_gateway_settings ORDER BY id LIMIT 1')->fetchColumn();

if (\$id === false) {
    \$statement = \$pdo->prepare('INSERT INTO local_gateway_settings (gateway_url, gateway_wg_ip, ca_sha256, ca_pem_path, trusted_at, created_at, updated_at) VALUES (:gateway_url, :gateway_wg_ip, :ca_sha256, :ca_pem_path, :trusted_at, :now, :now)');
    \$statement->execute(['gateway_url' => {$gatewayUrlValue}, 'gateway_wg_ip' => {$gatewayIpValue}, 'ca_sha256' => hash('sha256', \$rootCa), 'ca_pem_path' => \$pemPath, 'trusted_at' => \$now, 'now' => \$now]);
} else {
    \$statement = \$pdo->prepare('UPDATE local_gateway_settings SET gateway_url = :gateway_url, gateway_wg_ip = :gateway_wg_ip, ca_sha256 = :ca_sha256, ca_pem_path = :ca_pem_path, trusted_at = :trusted_at, updated_at = :now WHERE id = :id');
    \$statement->execute(['gateway_url' => {$gatewayUrlValue}, 'gateway_wg_ip' => {$gatewayIpValue}, 'ca_sha256' => hash('sha256', \$rootCa), 'ca_pem_path' => \$pemPath, 'trusted_at' => \$now, 'now' => \$now, 'id' => \$id]);
}
PHP;

        $this->writeOperatorCliConfig($operator, $key, 'https://'.self::GatewayWireGuardIp, '/home/'.$this->host->config->operatorUser.'/.config/orbit/gateway-ca/orbit.crt', hash('sha256', $rootCa));
        $this->runOperatorPhp($operator, $key, $php, timeoutSeconds: 120);
    }

    private function operatorGatewayDatabasePath(): string
    {
        return '/home/'.$this->host->config->operatorUser.'/.config/orbit/gateway.sqlite';
    }

    private function runOperatorPhp(IncusInstance $operator, SshKeyPair $key, string $php, int $timeoutSeconds = 60): void
    {
        E2ECommand::ssh(
            $operator,
            $this->host->config->operatorUser,
            $key,
            'php -r '.escapeshellarg($php),
            timeoutSeconds: $timeoutSeconds,
        );
    }

    private function installPrivateSshKey(IncusInstance $instance, SshKeyPair $key, string $user): void
    {
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $sshDirectory = "{$home}/.ssh";
        $privateKey = "{$sshDirectory}/id_ed25519";

        E2ECommand::exec(
            $instance,
            sprintf(
                'install -d -m 700 -o %s -g %s %s',
                escapeshellarg($user),
                escapeshellarg($user),
                escapeshellarg($sshDirectory),
            ),
            "Could not prepare {$user} SSH directory",
        );

        $instance->copyFileToInstance($key->privateKeyPath, $privateKey);

        E2ECommand::exec(
            $instance,
            sprintf(
                'chown %s:%s %s && chmod 600 %s',
                escapeshellarg($user),
                escapeshellarg($user),
                escapeshellarg($privateKey),
                escapeshellarg($privateKey),
            ),
            "Could not install {$user} SSH key",
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function installRealWireGuard(array $instances): void
    {
        $operator = $instances['operator'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($operator === null || $gateway === null) {
            return;
        }

        $gatewayProviderIp = $gateway->waitForIpv4();
        $wgEasy = new E2EWgEasyGateway;
        $wgEasy->start($gateway, $gatewayProviderIp);

        $mesh = $this->meshFor($instances, $gatewayProviderIp);
        $wgEasy->configurePeers($gateway, $mesh->wgEasyPeers());

        foreach (['gateway', 'operator', 'dev', 'prod', 'agent', 'ingress', 'websocket'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $mesh->installRole($instances[$role], $role);
        }

        $mesh->verifyRole($gateway, 'gateway', array_values(array_filter([
            'operator',
            isset($instances['dev']) ? 'dev' : null,
            isset($instances['prod']) ? 'prod' : null,
            isset($instances['agent']) ? 'agent' : null,
            isset($instances['ingress']) ? 'ingress' : null,
            isset($instances['websocket']) ? 'websocket' : null,
        ])));
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function meshFor(array $instances, string $gatewayProviderIp): E2EWireGuardMesh
    {
        $generator = app(WireGuardKeyGenerator::class);
        $gatewayHost = $generator->generateKeyPair();
        $operator = $generator->generateKeyPair();
        $dev = isset($instances['dev']) ? $generator->generateKeyPair() : null;
        $prod = isset($instances['prod']) ? $generator->generateKeyPair() : null;
        $agent = isset($instances['agent']) ? $generator->generateKeyPair() : null;
        $ingress = isset($instances['ingress']) ? $generator->generateKeyPair() : null;
        $websocket = isset($instances['websocket']) ? $generator->generateKeyPair() : null;
        $wgEasyPublicKey = trim($instances['gateway']->exec('docker exec wg-easy wg show wg0 public-key')->output());

        return E2EWireGuardMesh::standard(
            gatewayProviderIp: $gatewayProviderIp,
            wgEasyPublicKey: $wgEasyPublicKey,
            gatewayHostPrivateKey: $gatewayHost['private_key'],
            gatewayHostPublicKey: $gatewayHost['public_key'],
            operatorPrivateKey: $operator['private_key'],
            operatorPublicKey: $operator['public_key'],
            devPrivateKey: $dev['private_key'] ?? null,
            devPublicKey: $dev['public_key'] ?? null,
            prodPrivateKey: $prod['private_key'] ?? null,
            prodPublicKey: $prod['public_key'] ?? null,
            agentPrivateKey: $agent['private_key'] ?? null,
            agentPublicKey: $agent['public_key'] ?? null,
            ingressPrivateKey: $ingress['private_key'] ?? null,
            ingressPublicKey: $ingress['public_key'] ?? null,
            websocketPrivateKey: $websocket['private_key'] ?? null,
            websocketPublicKey: $websocket['public_key'] ?? null,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    private function finalizeInstances(E2ETopologyKind $kind, array $instances): array
    {
        $manifest = [];
        $snapshot = IncusTopologyTemplate::snapshotName($kind);

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $instance = $instances[$role];
            $name = $instance->name();

            $this->timer->measure("finalize.clear-known-hosts.{$role}", fn () => $this->clearKnownHosts($instance));

            if ($this->remoteSourcePath !== null) {
                $this->timer->measure("finalize.detach-source.{$role}", fn () => $this->detachPreparedSourceMount($instance));
            }

            $result = $this->timer->measure("finalize.stop.{$role}", fn () => $this->host->forceStopInstance($name));
            if (! $result->successful()) {
                throw new RuntimeException("Could not stop {$name}: {$result->errorOutput()}");
            }

            $this->timer->measure("finalize.delete-snapshot.{$role}", fn () => $this->host->deleteSnapshot($name, $snapshot));

            $result = $this->timer->measure("finalize.snapshot.{$role}", fn () => $this->host->snapshotInstance($name, $snapshot));
            if (! $result->successful()) {
                throw new RuntimeException("Could not snapshot {$name}: {$result->errorOutput()}");
            }

            $manifest[] = [
                'role' => $role,
                'name' => $name,
                'snapshot' => $snapshot,
            ];
        }

        return $manifest;
    }

    /**
     * Wipe every user's known_hosts inside the instance before snapshotting it.
     *
     * Bake-time SSH between roles populates `~/.ssh/known_hosts` with the
     * provider IPs the template peers had at bake time. Incus reuses provider
     * IPs across runs (the bridge DHCP pool is small), and clones get fresh
     * SSH host keys, so stale entries collide with new clones and trip
     * StrictHostKeyChecking inside production SSH paths (e.g. doctor probes
     * SSHing from the gateway clone into an app clone).
     *
     * Clearing per-user known_hosts before the snapshot keeps the snapshot
     * state hermetic — every lease starts with an empty trust file and lets
     * `StrictHostKeyChecking=accept-new` repopulate it cleanly.
     */
    private function clearKnownHosts(IncusInstance $instance): void
    {
        $instance->exec(
            'for d in /root /home/*; do '
                .'[ -d "$d/.ssh" ] || continue; '
                .'rm -f "$d/.ssh/known_hosts" "$d/.ssh/known_hosts.old"; '
            .'done',
            timeoutSeconds: 30,
        );
    }

    private function detachPreparedSourceMount(IncusInstance $instance): void
    {
        $this->host->run(
            'incus config device remove '.escapeshellarg($instance->name()).' orbit-source >/dev/null 2>&1 || true',
            timeoutSeconds: 30,
        );
    }

    /**
     * Rebuild selected role templates from the matching base source snapshots.
     *
     * For each selected role, this method copies the base template (with its
     * base snapshot) into the slug-namespaced template name, starts the VM,
     * overlays the current checkout bundle onto the existing installation, then
     * stops the VM and takes a fresh `clean-<source-kind>-<slug>` snapshot.
     *
     * Unselected roles are left absent so feature acquisition falls back to the
     * matching `base` artifacts for those roles.
     *
     * @param  list<string>  $selectedRoles  Incus role names (e.g. operator, gateway, dev, prod, agent)
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    public function buildSelectedRoles(E2ETopologyKind $sourceKind, array $selectedRoles, bool $replaceExisting = false): array
    {
        if ($this->remoteBundleDir === null) {
            throw new RuntimeException(
                'No provisioning bundle has been staged. Call useBundle() before buildSelectedRoles().'
            );
        }

        if (E2ETopologyArtifactNamespace::artifactSet() === E2ETopologyArtifactNamespace::BaseArtifactSet) {
            throw new RuntimeException('Set ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE when using selected Incus role rebakes; omit --roles to rebuild the shared base artifact set.');
        }

        $this->validateGatewayConsistency($selectedRoles);

        $workDirectory = $this->timer->measure('workdir', fn (): string => $this->createWorkDirectory());

        try {
            $key = $this->timer->measure('ssh-key', fn (): SshKeyPair => $this->createSshKeyPair($workDirectory));

            return $this->buildSelectedRoleTemplates($sourceKind, $selectedRoles, $key, $replaceExisting);
        } finally {
            $this->timer->measure('workdir.cleanup', fn () => $this->host->run('rm -rf '.escapeshellarg((string) $workDirectory)));
        }
    }

    /**
     * Validate that gateway + operator are selected together if either is included.
     *
     * Gateway and operator share CA trust, WireGuard mesh keys, and gateway API
     * credentials baked into the operator template. Rebuilding one without the
     * other produces an incoherent artifact set because the CA fingerprint or
     * WireGuard peer keys in the new template will not match the ones in the old
     * base template for the counterpart role.
     *
     * @param  list<string>  $selectedRoles
     */
    private function validateGatewayConsistency(array $selectedRoles): void
    {
        $hasGateway = in_array('gateway', $selectedRoles, true);
        $hasOperator = in_array('operator', $selectedRoles, true);

        if ($hasGateway && ! $hasOperator) {
            throw new RuntimeException(
                "Selected roles include 'gateway' but not 'operator'. Gateway and operator share CA trust and WireGuard contracts; both must be rebuilt together for a coherent artifact set. Add 'operator' to --roles."
            );
        }

        if ($hasOperator && ! $hasGateway) {
            throw new RuntimeException(
                "Selected roles include 'operator' but not 'gateway'. Gateway and operator share CA trust and WireGuard contracts; both must be rebuilt together for a coherent artifact set. Add 'gateway' to --roles."
            );
        }
    }

    /**
     * @param  list<string>  $selectedRoles
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    private function buildSelectedRoleTemplates(
        E2ETopologyKind $sourceKind,
        array $selectedRoles,
        SshKeyPair $key,
        bool $replaceExisting,
    ): array {
        $baseSnapshotName = IncusTopologyTemplate::baseSnapshotName($sourceKind);
        $slugSnapshotName = IncusTopologyTemplate::snapshotName($sourceKind);
        $manifest = [];

        // Pre-flight: validate base templates exist and delete any stale slug templates.
        foreach ($selectedRoles as $role) {
            $baseTemplateName = IncusTopologyTemplate::baseTemplateName($sourceKind, $role);
            $slugTemplateName = IncusTopologyTemplate::templateName($sourceKind, $role);

            if (! $this->host->instanceExists($baseTemplateName)) {
                throw new RuntimeException(
                    "Base template [{$baseTemplateName}] not found. Prepare the base artifact set first with composer e2e:prepare-topology."
                );
            }

            if (! $this->host->snapshotExists($baseTemplateName, $baseSnapshotName)) {
                throw new RuntimeException(
                    "Base snapshot [{$baseTemplateName}/{$baseSnapshotName}] not found. Prepare the base artifact set first."
                );
            }

            if ($this->host->instanceExists($slugTemplateName)) {
                if (! $replaceExisting) {
                    throw new RuntimeException(
                        "Template [{$slugTemplateName}] already exists. Pass --force to replace it."
                    );
                }

                $deleteResult = $this->host->deleteInstance($slugTemplateName);
                if (! $deleteResult->successful()) {
                    throw new RuntimeException("Could not delete existing template [{$slugTemplateName}]: {$deleteResult->errorOutput()}");
                }
            }
        }

        // Copy base template/snapshot for each selected role into the slug namespace.
        $copyLines = [];
        $waitLines = [];
        foreach (array_values($selectedRoles) as $index => $role) {
            $baseTemplateName = IncusTopologyTemplate::baseTemplateName($sourceKind, $role);
            $slugTemplateName = IncusTopologyTemplate::templateName($sourceKind, $role);
            $pid = 'PID_COPY_'.($index + 1);
            $storageArg = $this->host->storagePoolArgument();
            $storageArg = $storageArg !== '' ? " {$storageArg}" : '';
            $copyLines[] = sprintf(
                'incus copy %s/%s %s%s & %s=$!',
                escapeshellarg($baseTemplateName),
                escapeshellarg($baseSnapshotName),
                escapeshellarg($slugTemplateName),
                $storageArg,
                $pid,
            );
            $waitLines[] = "wait \${$pid}";
        }

        $copyScript = implode("\n", [...$copyLines, ...$waitLines]);
        $copyResult = $this->timer->measure('copy.base-templates', fn () => $this->host->run($copyScript, timeoutSeconds: 600));
        if (! $copyResult->successful()) {
            throw new RuntimeException("Could not copy base templates: {$copyResult->errorOutput()}");
        }

        // Start each selected VM, overlay the bundle source, then stop and snapshot.
        foreach ($selectedRoles as $role) {
            $slugTemplateName = IncusTopologyTemplate::templateName($sourceKind, $role);

            $startResult = $this->timer->measure("selected.start.{$role}", fn () => $this->host->startInstance($slugTemplateName));
            if (! $startResult->successful()) {
                throw new RuntimeException("Could not start [{$slugTemplateName}]: {$startResult->errorOutput()}");
            }

            $instance = new IncusInstance($this->host, $slugTemplateName);
            $this->timer->measure("selected.agent-ready.{$role}", fn () => $instance->waitForAgent());

            $this->timer->measure("selected.bundle-overlay.{$role}", fn () => $this->applyBundleOverlay($instance, $role, $key));

            $this->timer->measure("selected.clear-known-hosts.{$role}", fn () => $this->clearKnownHosts($instance));

            $stopResult = $this->timer->measure("selected.stop.{$role}", fn () => $this->host->stopInstance($slugTemplateName));
            if (! $stopResult->successful()) {
                throw new RuntimeException("Could not stop [{$slugTemplateName}]: {$stopResult->errorOutput()}");
            }

            $snapResult = $this->timer->measure("selected.snapshot.{$role}", fn () => $this->host->snapshotInstance($slugTemplateName, $slugSnapshotName));
            if (! $snapResult->successful()) {
                throw new RuntimeException("Could not snapshot [{$slugTemplateName}/{$slugSnapshotName}]: {$snapResult->errorOutput()}");
            }

            $manifest[] = [
                'role' => $role,
                'name' => $slugTemplateName,
                'snapshot' => $slugSnapshotName,
            ];
        }

        return $manifest;
    }

    /**
     * Overlay the current checkout bundle onto an already-provisioned VM.
     *
     * Pushes the staged source archive via incus file push, extracts it over
     * the existing orbit installation, then runs composer install in each
     * sub-app to pick up dependency changes. Composer cache is forwarded when
     * present in the bundle.
     *
     * This does not call install-orbit because the VM is already provisioned;
     * we only need to refresh the source tree and vendor directories.
     */
    private function applyBundleOverlay(IncusInstance $instance, string $role, SshKeyPair $key): void
    {
        $bundleDir = (string) $this->remoteBundleDir;
        // The bundle dir on the host ends in 'orbit-e2e-bundle'; incus file push
        // preserves the last path component, so /var/tmp/orbit-e2e-bundle is
        // created inside the guest.
        $guestBundleDir = '/var/tmp/'.basename($bundleDir);
        $sourceArchive = "{$guestBundleDir}/orbit-source.tar.gz";

        // Clear any stale overlay bundle from a previous failed run.
        $instance->exec('rm -rf '.escapeshellarg($guestBundleDir), timeoutSeconds: 30);

        // Push bundle into the guest.
        $pushResult = $this->host->run(sprintf(
            'incus file push -r -p %s %s/var/tmp/',
            escapeshellarg(rtrim($bundleDir, '/')),
            escapeshellarg($instance->name()),
        ), timeoutSeconds: 300);

        if (! $pushResult->successful()) {
            throw new RuntimeException("Could not push overlay bundle into [{$instance->name()}]: {$pushResult->errorOutput()}");
        }

        // Determine the orbit install path for this role.
        $orbitHome = '/home/'.$this->host->config->operatorUser;
        $orbitPath = "{$orbitHome}/orbit";

        // Extract source archive directly over the existing orbit checkout.
        $extractScript = sprintf(
            'mkdir -p %s && tar --no-same-owner -xzf %s -C %s',
            escapeshellarg($orbitPath),
            escapeshellarg($sourceArchive),
            escapeshellarg($orbitPath),
        );

        $extractResult = $instance->exec(
            'sudo -iu orbit bash -lc '.escapeshellarg($extractScript),
            timeoutSeconds: 300,
        );

        if (! $extractResult->successful()) {
            throw new RuntimeException("Could not extract source archive on [{$instance->name()}] role={$role}: {$extractResult->errorOutput()}");
        }

        // Run composer install in sub-apps to pick up dependency changes.
        $hasComposerCache = $this->host->run(
            'test -d '.escapeshellarg("{$bundleDir}/composer-cache"),
            timeoutSeconds: 5,
        )->successful();

        $composerCacheEnv = $hasComposerCache
            ? "COMPOSER_CACHE_DIR={$guestBundleDir}/composer-cache "
            : '';

        // Write the composer install script to a temp file and execute it.
        // Using a script file avoids multi-line quoting issues across the
        // incus exec → sh -lc → bash -lc invocation stack.
        $scriptPath = '/tmp/orbit-e2e-selected-rebake-composer.sh';
        $scriptContent = "#!/bin/bash\nset -euo pipefail\n"
            ."cd {$orbitPath}\n"
            ."if command -v composer >/dev/null 2>&1; then\n"
            ."  for app in apps/gateway apps/cli apps/e2e packages/core apps/docs; do\n"
            ."    if [ -f \"\$app/composer.json\" ]; then\n"
            ."      {$composerCacheEnv}composer --working-dir=\"\$app\" install --no-interaction --no-progress --prefer-dist --optimize-autoloader 2>&1 || true\n"
            ."    fi\n"
            ."  done\n"
            ."fi\n";

        // Write the script into the guest via incus exec (sh -lc handles the heredoc).
        $writeResult = $instance->exec(
            "cat > {$scriptPath} <<'SCRIPT'\n{$scriptContent}SCRIPT\nchmod +x {$scriptPath}\nchown orbit:orbit {$scriptPath}",
            timeoutSeconds: 30,
        );

        if (! $writeResult->successful()) {
            throw new RuntimeException("Could not write composer install script on [{$instance->name()}]: {$writeResult->errorOutput()}");
        }

        $composerResult = $instance->exec(
            'sudo -iu orbit bash -lc '.escapeshellarg("bash {$scriptPath}"),
            timeoutSeconds: 600,
        );

        if (! $composerResult->successful()) {
            throw new RuntimeException("Composer install failed on [{$instance->name()}] role={$role}: {$composerResult->errorOutput()}");
        }

        // Cleanup overlay bundle from guest.
        $instance->exec('rm -rf '.escapeshellarg($guestBundleDir), timeoutSeconds: 30);
    }

    private function launchBase(string $target): void
    {
        if ($this->remoteSourcePath !== null) {
            $result = $this->host->run($this->launchTopologyInstanceCommand($target), timeoutSeconds: $this->host->config->timeoutSeconds);

            if (! $result->successful()) {
                throw new RuntimeException("Could not launch {$target} from {$this->host->config->baseImage}: {$result->errorOutput()}");
            }

            return;
        }

        $sourceImageAlias = $this->host->config->baseImage;
        $result = $this->host->launchTopologyInstance($sourceImageAlias, $target, timeoutSeconds: $this->host->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not launch {$target} from {$sourceImageAlias}: {$result->errorOutput()}");
        }
    }

    private function launchTopologyInstanceCommand(string $name): string
    {
        if ($this->remoteSourcePath !== null) {
            $parts = [
                'incus init',
                escapeshellarg($this->host->config->baseImage),
                escapeshellarg($name),
                '--vm',
                sprintf(
                    '--config=limits.cpu=%s --config=limits.memory=%s --device root,size=%s',
                    escapeshellarg($this->host->config->topologyCpus),
                    escapeshellarg($this->host->config->topologyMemory),
                    escapeshellarg($this->host->config->topologyRootSize),
                ),
                $this->host->storagePoolArgument(),
            ];

            return implode("\n", [
                implode(' ', array_filter($parts)),
                sprintf(
                    'incus config device add %s orbit-source disk source=%s path=%s shift=true',
                    escapeshellarg($name),
                    escapeshellarg($this->remoteSourcePath),
                    escapeshellarg(self::PreparedSourceMountPath),
                ),
                'incus start '.escapeshellarg($name).' >/dev/null',
            ]);
        }

        $parts = [
            'incus launch',
            escapeshellarg($this->host->config->baseImage),
            escapeshellarg($name),
            '--vm',
            sprintf(
                '--config=limits.cpu=%s --config=limits.memory=%s --device root,size=%s',
                escapeshellarg($this->host->config->topologyCpus),
                escapeshellarg($this->host->config->topologyMemory),
                escapeshellarg($this->host->config->topologyRootSize),
            ),
            $this->host->storagePoolArgument(),
            '>/dev/null',
        ];

        return implode(' ', array_filter($parts));
    }
}
