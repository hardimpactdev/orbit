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

    private const string IngressWireGuardIp = '10.6.0.7';

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

            return $manifests[$kind->value]
                ?? $manifests[E2EPreparedTopology::incusSourceKindFor($kind)->value]
                ?? throw new RuntimeException("Prepared topology manifest [{$kind->value}] was not built.");
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
     * @return array<string, list<array{role: string, name: string, snapshot: string}>>
     */
    private function buildStages(E2ETopologyKind $target, SshKeyPair $key, ?E2ETopologyKind $reusableBase): array
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

        foreach (array_slice($stages, $startIndex) as $stage) {
            $instances = match ($stage) {
                E2ETopologyKind::Operator => $this->buildOperatorStage($key),
                E2ETopologyKind::OperatorGateway => $this->buildGatewayStage($key),
                E2ETopologyKind::OperatorGatewayAppdev => $this->buildDevelopmentAppStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprod => $this->buildProductionAppStage($key),
                E2ETopologyKind::OperatorGatewayAgent => $this->buildAgentOnlyStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => $this->buildPreparedFullStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprodIngress => $this->buildPreparedDedicatedIngressStage($key),
                E2ETopologyKind::OperatorGatewayAppprodIngress => $this->buildIngressProductionStage($key),
                E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket => $this->buildPreparedFullWebSocketStage($key),
                E2ETopologyKind::OperatorGatewayAppdevWebsocket,
                E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket => throw new RuntimeException("Build websocket topology source [{$stage->value}] through [".E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket->value.'].'),
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
     * @return array<string, IncusInstance>
     */
    private function buildPreparedFullWebSocketStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['operator', 'gateway'], $key);

        $this->timer->measure('prepared-websocket.real-wireguard.retarget', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared-websocket.gateway.api.start', fn () => E2EGatewayApi::start($instances['gateway'], 'template-prepared-full-websocket'));
        $this->timer->measure('prepared-websocket.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared-websocket.gateway.wg-easy.ready', fn () => $this->waitForGatewayWireGuard($instances['gateway']));
        $this->timer->measure('prepared-websocket.gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($instances['gateway'], $key));

        $kind = E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket;
        $dev = $this->launchBaseRole('dev', $key, $kind);
        $prod = $this->launchBaseRole('prod', $key, $kind);
        $agent = $this->launchBaseRole('agent', $key, $kind);

        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $dev->waitForIpv4());
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $prod->waitForIpv4());
        $agentIp = $this->timer->measure('agent.ipv4', fn (): string => $agent->waitForIpv4());

        $instances['dev'] = $dev;
        $instances['prod'] = $prod;
        $instances['agent'] = $agent;

        $this->timer->measure('prepared-websocket.downstream.bake', fn () => $this->runPreparedDownstreamBakeInParallel(
            $instances['gateway'],
            $devIp,
            $prodIp,
            $agentIp,
        ));
        $this->timer->measure('prepared-websocket.real-wireguard', fn () => $this->installRealWireGuard($instances));
        $this->timer->measure('prepared-websocket.gateway.api.ready-after-downstream-bake', fn () => E2EGatewayApi::waitForGatewayApi($instances['operator'], $this->host->config->operatorUser, $key));
        $this->timer->measure('prepared-websocket.dev.database-redis-seed', fn () => $this->seedAppdevDatabaseAndRedis($instances['gateway']));
        $this->timer->measure('prepared-websocket.e2e-deps', fn () => $this->installE2EBaseDependencies($instances));
        $this->timer->measure('prepared-websocket.websocket.bake', fn () => $this->runPreparedWebSocketBake(
            $instances['gateway'],
            $devIp,
        ));

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

    private function runPreparedDownstreamBakeInParallel(
        IncusInstance $gateway,
        string $devHost,
        string $prodHost,
        string $agentHost,
    ): void {
        $devCommand = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-app-node',
            escapeshellarg('app-dev-1'),
            '--role=app-dev',
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
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
        E2ECommand::exec(
            $gateway,
            $scriptPathArgument,
            'Could not bake prepared downstream nodes in parallel',
            timeoutSeconds: 900,
        );
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
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
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

    private function runPreparedWebSocketBake(IncusInstance $gateway, string $devHost): void
    {
        $command = E2ECommand::gatewayArtisanCommand(implode(' ', [
            'orbit:internal:bake-websocket-node',
            escapeshellarg('app-dev-1'),
            '--host='.escapeshellarg($devHost),
            '--wireguard-address='.escapeshellarg(self::DevWireGuardIp),
            '--gateway-endpoint='.escapeshellarg(self::GatewayWireGuardIp),
            '--user='.escapeshellarg($this->host->config->bootstrapUser),
            '--redis-node='.escapeshellarg('app-dev-1'),
            '--converge-runtime',
        ]));

        E2ECommand::exec(
            $gateway,
            $command,
            'Could not bake prepared websocket node',
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
        $sourceImageAlias = $this->host->config->baseImage;
        $result = $this->host->launchTopologyInstance($sourceImageAlias, $target, timeoutSeconds: $this->host->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not launch {$target} from {$sourceImageAlias}: {$result->errorOutput()}");
        }
    }
}
