<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\WireGuard\WireGuardKeyGenerator;
use RuntimeException;

class IncusTopologyBuilder
{
    private ?string $remoteBundleDir = null;

    private const string GatewayWireGuardIp = '10.6.0.2';

    private const string OperatorWireGuardIp = '10.6.0.3';

    private const string DevWireGuardIp = '10.6.0.4';

    private const string ProdWireGuardIp = '10.6.0.5';

    private const string AgentWireGuardIp = '10.6.0.6';

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
        $plan = $this->timer->measure('preflight', fn (): array => $this->validatePreFlight($kind, $replaceExisting));

        $workDirectory = $this->timer->measure('workdir', fn (): string => $this->createWorkDirectory());

        try {
            $key = $this->timer->measure('ssh-key', fn (): SshKeyPair => $this->createSshKeyPair($workDirectory));
            $manifests = $this->buildStages($plan['target'], $key, $plan['reusableBase']);

            return $manifests[$kind->value];
        } finally {
            $this->timer->measure('workdir.cleanup', fn () => $this->host->run('rm -rf '.escapeshellarg((string) $workDirectory)));
        }
    }

    /**
     * @return array{
     *     target: E2ETopologyKind,
     *     reusableBase: E2ETopologyKind|null,
     * }
     */
    private function validatePreFlight(E2ETopologyKind $kind, bool $replaceExisting): array
    {
        $baseImage = $this->host->config->baseImage;

        if (! $this->host->imageExists($baseImage)) {
            throw new RuntimeException("Required base image [{$baseImage}] not found on host.");
        }

        if ($this->remoteBundleDir === null) {
            throw new RuntimeException(
                'No provisioning bundle has been staged. Call useBundle() before build().'
            );
        }

        $reusableBase = $replaceExisting
            ? $this->resolveReusableBaseStage($kind)
            : null;

        foreach ($this->templateNamesForRefresh($kind, $reusableBase, includeLegacyNames: $replaceExisting) as $name) {
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
        if ($kind === E2ETopologyKind::OperatorGatewayAppdevAppprodAgent) {
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
     * @return array<string, list<array{role: string, name: string, snapshot: string}>>
     */
    private function buildStages(E2ETopologyKind $target, SshKeyPair $key, ?E2ETopologyKind $reusableBase): array
    {
        $manifests = [];
        $instances = [];
        $stages = $this->stagesThrough($target);
        $startIndex = 0;

        if ($reusableBase !== null && ! $this->usesCopiedReusableBase($target, $reusableBase)) {
            $this->deleteSnapshotsAfterReusableBase($reusableBase);
            $this->restoreReusableBaseStage($reusableBase);
        }

        if ($reusableBase !== null) {
            $baseIndex = array_search($reusableBase, $stages, true);

            if ($baseIndex === false) {
                throw new RuntimeException("Reusable base [{$reusableBase->value}] is not a prerequisite for [{$target->value}].");
            }

            $startIndex = $baseIndex + 1;
        }

        foreach (array_slice($stages, $startIndex) as $stage) {
            $instances = match ($stage) {
                E2ETopologyKind::Operator => $this->buildOperatorStage($key),
                E2ETopologyKind::OperatorGateway => $this->buildGatewayStage($key),
                E2ETopologyKind::OperatorGatewayAppdev => $this->buildDevelopmentAppStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprod => $this->buildProductionAppStage($key),
                E2ETopologyKind::OperatorGatewayAgent => $this->buildAgentOnlyStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => $this->buildPreparedFullStage($key),
                E2ETopologyKind::OperatorGatewayAppprodIngress => $this->buildIngressProductionStage($key),
            };

            $manifests[$stage->value] = $this->finalizeInstances($stage, $instances);
        }

        return $manifests;
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

    private function deleteSnapshotsAfterReusableBase(E2ETopologyKind $reusableBase): void
    {
        $baseRoles = IncusTopologyTemplate::rolesFor($reusableBase);
        $stages = E2ETopologyKind::cases();
        $baseIndex = array_search($reusableBase, $stages, true);

        if ($baseIndex === false) {
            throw new RuntimeException("Reusable base [{$reusableBase->value}] has no ordered stage.");
        }

        $deleted = [];

        foreach (array_slice($stages, $baseIndex + 1) as $stage) {
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
        $this->timer->measure('operator.agent.initial', fn () => $operator->waitForAgent());
        $this->timer->measure('operator.cloud-init', fn () => $this->host->waitForCloudInit($operatorName));
        $this->timer->measure('operator.agent.after-cloud-init', fn () => $operator->waitForAgent());
        $this->timer->measure('operator.provision', fn () => $this->host->provisionInstance($operatorName, 'operator', (string) $this->remoteBundleDir, $this->host->config->operatorUser));
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

        $this->timer->measure('gateway.provision', fn () => $this->host->provisionInstance($gateway->name(), 'gateway', (string) $this->remoteBundleDir));
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
        $this->timer->measure('dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
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
     * @return array<string, IncusInstance>
     */
    private function buildPreparedFullStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator', 'gateway'], $key);

        $this->timer->measure('prepared.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-prepared-full'));
        $this->timer->measure('prepared.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));
        $this->timer->measure('prepared.gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($instances['gateway'], $key));

        $dev = $this->launchBaseRole('dev', $key, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
        $prod = $this->launchBaseRole('prod', $key, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
        $agent = $this->launchBaseRole('agent', $key, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);

        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $dev->waitForIpv4());
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $prod->waitForIpv4());
        $agentIp = $this->timer->measure('agent.ipv4', fn (): string => $agent->waitForIpv4());

        $instances['dev'] = $dev;
        $instances['prod'] = $prod;
        $instances['agent'] = $agent;

        $this->timer->measure('prepared.downstream.bake', fn () => $this->runPreparedDownstreamBakeInParallel(
            $instances['gateway'],
            $devIp,
            $prodIp,
            $agentIp,
        ));
        $this->timer->measure('prepared.gateway.api.ready-after-node-new', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared.e2e-deps', fn () => $this->installE2EBaseDependencies($instances));
        $this->timer->measure('dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
        $this->timer->measure('prepared.real-wireguard', fn () => $this->installRealWireGuard($instances));

        return $instances;
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

    private function seedAppdevDatabaseAndRedis(IncusInstance $gateway): void
    {
        $scriptPath = '/tmp/orbit-e2e-appdev-database-redis.php';
        $scriptPathArgument = escapeshellarg($scriptPath);
        $php = "<?php\n\n".E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp();

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPathArgument} <<'PHP'\n{$php}\nPHP\nchmod 644 {$scriptPathArgument}\nchown orbit:orbit {$scriptPathArgument}",
            'Could not write app-dev database and Redis registry seed script',
            timeoutSeconds: 30,
        );
        E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg('require '.var_export($scriptPath, true).';'),
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
        $this->timer->measure("{$role}.agent.initial", fn () => $instance->waitForAgent());
        $this->timer->measure("{$role}.cloud-init", fn () => $this->host->waitForCloudInit($name));
        $this->timer->measure("{$role}.agent.after-cloud-init", fn () => $instance->waitForAgent());
        $this->timer->measure("{$role}.ssh-authorize", fn () => $instance->authorizeSsh($this->host->config->bootstrapUser, $key));
        $this->timer->measure("{$role}.ssh-ready", fn () => $instance->waitForSsh($this->host->config->bootstrapUser, $key));

        return $instance;
    }

    private function useWireGuardGatewayUrl(IncusInstance $operator, SshKeyPair $key): void
    {
        $gatewayUrl = var_export('https://'.self::GatewayWireGuardIp, true);
        $gatewayIp = var_export(self::GatewayWireGuardIp, true);

        $php = <<<PHP
\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->gateway_url = {$gatewayUrl};
\$settings->gateway_wg_ip = {$gatewayIp};
\$settings->save();
PHP;

        E2ECommand::ssh(
            $operator,
            $this->host->config->operatorUser,
            $key,
            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 60,
        );
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

    private function runPreparedDownstreamBakeInParallel(
        IncusInstance $gateway,
        string $devHost,
        string $prodHost,
        string $agentHost,
    ): void {
        $devCommand = implode(' ', [
            'php apps/gateway/artisan orbit:internal:bake-app-node',
            escapeshellarg('app-dev-1'),
            '--role=app-dev',
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--tld='.escapeshellarg('test'),
        ]);
        $prodIngressCommand = implode(' ', [
            'php apps/gateway/artisan orbit:internal:bake-ingress-node',
            escapeshellarg('app-prod-1'),
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
        ]);
        $prodAppCommand = implode(' ', [
            'php apps/gateway/artisan orbit:internal:bake-app-node',
            escapeshellarg('app-prod-1'),
            '--role=app-prod',
            '--host='.escapeshellarg($prodHost),
            '--wireguard-address='.escapeshellarg(self::ProdWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--ingress-node='.escapeshellarg('app-prod-1'),
        ]);
        $prodCommand = "{$prodIngressCommand} && {$prodAppCommand}";
        $agentCommand = implode(' ', [
            'php apps/gateway/artisan orbit:internal:bake-agent-node',
            escapeshellarg('agent-1'),
            '--host='.escapeshellarg($agentHost),
            '--wireguard-address='.escapeshellarg(self::AgentWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--tld='.escapeshellarg('agent'),
        ]);
        $script = <<<BASH
set -euo pipefail;
cd /home/orbit/orbit;
({$devCommand}) > /tmp/orbit-e2e-bake-dev.log 2>&1 & PID_BAKE_DEV=\$!;
({$prodCommand}) > /tmp/orbit-e2e-bake-prod.log 2>&1 & PID_BAKE_PROD=\$!;
({$agentCommand}) > /tmp/orbit-e2e-bake-agent.log 2>&1 & PID_BAKE_AGENT=\$!;

STATUS=0;
wait "\$PID_BAKE_DEV" || { CODE=\$?; echo "bake app-dev failed" >&2; cat /tmp/orbit-e2e-bake-dev.log >&2 || true; if [ "\$STATUS" -eq 0 ]; then STATUS=\$CODE; fi; };
wait "\$PID_BAKE_PROD" || { CODE=\$?; echo "bake app-prod failed" >&2; cat /tmp/orbit-e2e-bake-prod.log >&2 || true; if [ "\$STATUS" -eq 0 ]; then STATUS=\$CODE; fi; };
wait "\$PID_BAKE_AGENT" || { CODE=\$?; echo "bake agent failed" >&2; cat /tmp/orbit-e2e-bake-agent.log >&2 || true; if [ "\$STATUS" -eq 0 ]; then STATUS=\$CODE; fi; };
exit "\$STATUS";
BASH;
        $scriptPath = '/tmp/orbit-e2e-prepared-bake.sh';
        $scriptPathArgument = escapeshellarg($scriptPath);

        E2ECommand::exec(
            $gateway,
            "cat > {$scriptPathArgument} <<'BASH'\n{$script}\nBASH\nchmod 755 {$scriptPathArgument}\nchown orbit:orbit {$scriptPathArgument}",
            'Could not write prepared downstream bake script',
            timeoutSeconds: 30,
        );
        E2ECommand::orbit(
            $gateway,
            $scriptPathArgument,
            'Could not bake prepared downstream nodes in parallel',
            timeoutSeconds: 900,
        );
    }

    private function waitForGatewayWireGuard(IncusInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            'deadline=$((SECONDS+180)); until test -f /home/orbit/.wg-easy/wg-easy.db && docker exec wg-easy ip link show wg0 >/dev/null 2>&1; do if [ "$SECONDS" -ge "$deadline" ]; then docker ps -a; docker logs --tail=120 wg-easy 2>&1 || true; exit 1; fi; sleep 2; done',
            "wg-easy did not become ready on {$gateway->name()}",
            timeoutSeconds: 210,
        );
    }

    private function bootstrapGatewayLocal(IncusInstance $gateway, string $publicHost): void
    {
        E2ECommand::orbit(
            $gateway,
            sprintf(
                'cd /home/orbit/orbit && php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway %s --public-host=%s --skip-runtime-install',
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

        $php = <<<PHP
\$gateway = \\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'gateway'],
    [
        'tld' => null,
        'platform' => 'unknown',
        'host' => {$gatewayIpValue},
        'wireguard_address' => {$gatewayIpValue},
        'gateway_endpoint' => null,
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
    ],
);

\\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
    ['node_id' => \$gateway->id, 'role' => 'gateway'],
    ['status' => 'active', 'settings' => [], 'last_error' => null, 'converged_at' => now()],
);

\\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
    ['node_id' => \$gateway->id, 'role' => 'vpn'],
    [
        'status' => 'active',
        'settings' => [
            'public_endpoint' => {$gatewayPublicEndpointValue},
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ],
        'last_error' => null,
        'converged_at' => now(),
    ],
);
PHP;

        E2ECommand::ssh(
            $operator,
            $this->host->config->operatorUser,
            $key,
            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 120,
        );
    }

    private function trustGatewayCaOnOperator(IncusInstance $operator, IncusInstance $gateway, SshKeyPair $key): void
    {
        $rootCa = E2ECommand::orbit(
            $gateway,
            'cd /home/orbit/orbit && php apps/gateway/artisan tinker --execute='.escapeshellarg('echo app(\App\Services\Ca\OrbitCaService::class)->rootCert();'),
            'Could not read gateway root CA',
            timeoutSeconds: 60,
        )->output();

        $rootCaValue = var_export($rootCa, true);
        $gatewayUrlValue = var_export('https://'.self::GatewayWireGuardIp, true);
        $gatewayIpValue = var_export(self::GatewayWireGuardIp, true);

        $php = <<<PHP
\$rootCa = {$rootCaValue};
\$pemPath = storage_path('app/orbit/gateway-ca/orbit.crt');
\\Illuminate\\Support\\Facades\\File::ensureDirectoryExists(dirname(\$pemPath));
\\Illuminate\\Support\\Facades\\File::put(\$pemPath, \$rootCa);

\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->fill([
    'gateway_url' => {$gatewayUrlValue},
    'gateway_wg_ip' => {$gatewayIpValue},
    'ca_sha256' => hash('sha256', \$rootCa),
    'ca_pem_path' => \$pemPath,
    'trusted_at' => now(),
]);
\$settings->save();
PHP;

        E2ECommand::ssh(
            $operator,
            $this->host->config->operatorUser,
            $key,
            'cd '.escapeshellarg('/home/'.$this->host->config->operatorUser.'/orbit').' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 120,
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

        foreach (['gateway', 'operator', 'dev', 'prod', 'agent', 'ingress'] as $role) {
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

        foreach ($instances as $role => $instance) {
            $name = $instance->name();

            $this->timer->measure("finalize.clear-known-hosts.{$role}", fn () => $this->clearKnownHosts($instance));

            $result = $this->timer->measure("finalize.stop.{$role}", fn () => $this->host->stopInstance($name));
            if (! $result->successful()) {
                throw new RuntimeException("Could not stop {$name}: {$result->errorOutput()}");
            }

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

    private function launchBase(string $target): void
    {
        $sourceImageAlias = $this->host->config->baseImage;
        $result = $this->host->launchTopologyInstance($sourceImageAlias, $target, timeoutSeconds: $this->host->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not launch {$target} from {$sourceImageAlias}: {$result->errorOutput()}");
        }
    }
}
