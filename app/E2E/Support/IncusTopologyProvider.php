<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class IncusTopologyProvider implements E2ETopologyProvider
{
    public function __construct(
        private E2EConfig $config,
    ) {}

    public function name(): string
    {
        return 'incus';
    }

    public function capabilities(): E2ETopologyCapabilities
    {
        return E2ETopologyCapabilities::vm();
    }

    public function availability(E2ETopologyKind $kind): ProviderAvailability
    {
        $host = IncusHostPool::fromEnvironment($this->config)->firstAvailableFor($kind);

        if ($host === null) {
            return ProviderAvailability::unavailable("prepared topology {$kind->value} is not available on any Incus host");
        }

        return ProviderAvailability::available("prepared topology {$kind->value} is available");
    }

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
    {
        $pool = IncusHostPool::fromEnvironment($this->config);
        $host = $timer->measure('availability', fn () => $pool->firstAvailableFor($kind));
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment();

        if ($host === null) {
            throw new \RuntimeException("Prepared topology {$kind->value} is not available on any Incus host");
        }

        $instances = IncusTopologyTemplate::clone($host, $kind, $runId, $timer);

        $sshKeyPair = $this->createSshKeyPair($host, $runId);
        $primaryUsers = $this->prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options, $networkPlan);
        $snapshotReset = $this->prepareSnapshotReset($host, $instances, $primaryUsers, $sshKeyPair, $timer, $options->startGatewayApi, $networkPlan);

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $sshKeyPair, $options, $networkPlan): array {
            $newInstances = IncusTopologyTemplate::clone($host, $kind, $runId, $cycleTimer);
            $newPrimaryUsers = $this->prepareInstances($newInstances, $this->config, $sshKeyPair, $cycleTimer, $options, $networkPlan);

            return [
                'instances' => $newInstances,
                'snapshotReset' => $this->prepareSnapshotReset($host, $newInstances, $newPrimaryUsers, $sshKeyPair, $cycleTimer, $options->startGatewayApi, $networkPlan),
            ];
        };

        return new E2ETopologyLease(
            kind: $kind,
            control: $instances['control'],
            gateway: $instances['gateway'] ?? null,
            dev: $instances['dev'] ?? null,
            prod: $instances['prod'] ?? null,
            sshKeyPair: $sshKeyPair,
            rebuild: $rebuild,
            snapshotReset: $snapshotReset,
            gatewayApiIp: $networkPlan->ipForRole('gateway'),
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function prepareInstances(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options, DockerTopologyNetworkPlan $networkPlan): array
    {
        $sshUsers = $this->sshUsersFor($instances, $config, $options);
        $primaryUsers = [];

        foreach ($sshUsers as $role => $primaryUser) {
            $instance = $instances[$role] ?? null;

            if ($instance === null) {
                continue;
            }

            $primaryUsers[$role] = $primaryUser;

            $timer->measure("command-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
        }

        $timer->measure('wireguard', fn () => $this->reestablishWireGuardRoutes($instances, $networkPlan));
        $timer->measure('retarget', fn () => $this->retargetTopology($instances, $config, $sshKeyPair, $networkPlan));
        $timer->measure('network-ready', fn () => $this->waitForPeerRoutes($instances, $networkPlan));

        if ($options->startGatewayApi && isset($instances['gateway'])) {
            $timer->measure('gateway-api.start', fn () => E2EGatewayApi::start(
                $instances['gateway'],
                'topology-lease',
                gatewayIp: $networkPlan->ipForRole('gateway'),
            ));
        }

        return $primaryUsers;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function sshUsersFor(array $instances, E2EConfig $config, E2ETopologyAcquisitionOptions $options): array
    {
        if ($options->sshUsers !== null) {
            return $options->sshUsers;
        }

        $users = [];

        foreach (array_keys($instances) as $role) {
            $users[$role] = match ($role) {
                'control' => $config->controlUser,
                default => 'orbit',
            };
        }

        return $users;
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function prepareSnapshotReset(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, bool $startGatewayApi, DockerTopologyNetworkPlan $networkPlan): ?\Closure
    {
        if (! $this->shouldPrepareSnapshotReset()) {
            return null;
        }

        $strategy = $this->resetStrategy();

        foreach ($instances as $role => $instance) {
            if ($strategy === 'stateful-restore') {
                $timer->measure("snapshot-stateful.{$role}", fn () => $instance->snapshotStatefully('lease-warm'));

                continue;
            }

            $timer->measure("snapshot.{$role}", fn () => $instance->snapshot('lease-clean'));
        }

        return $strategy === 'stateful-restore'
            ? $this->statefulResetFor($host, $instances, $primaryUsers, $sshKeyPair)
            : $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair, $startGatewayApi, $networkPlan);
    }

    private function shouldPrepareSnapshotReset(): bool
    {
        return in_array($this->resetStrategy(), ['snapshot-restore', 'stateful-restore'], true);
    }

    private function resetStrategy(): string
    {
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_RESET');

        return is_string($strategy) && $strategy !== '' ? $strategy : 'fresh-clone';
    }

    private function snapshotResetFor(array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, bool $startGatewayApi, DockerTopologyNetworkPlan $networkPlan): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($instances, $primaryUsers, $sshKeyPair, $startGatewayApi, $networkPlan): void {
            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
                $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
                $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
            }

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            $cycleTimer->measure('reset.wireguard', fn () => $this->reestablishWireGuardRoutes($instances, $networkPlan));
            $cycleTimer->measure('reset.retarget', fn () => $this->retargetTopology($instances, $this->config, $sshKeyPair, $networkPlan));
            $cycleTimer->measure('reset.network-ready', fn () => $this->waitForPeerRoutes($instances, $networkPlan));

            if ($startGatewayApi && isset($instances['gateway'])) {
                $cycleTimer->measure('reset.gateway-api.start', fn () => E2EGatewayApi::start(
                    $instances['gateway'],
                    'topology-reset',
                    gatewayIp: $networkPlan->ipForRole('gateway'),
                ));
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("reset.ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @param  array<string, string>  $primaryUsers
     */
    private function statefulResetFor(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($host, $instances, $primaryUsers, $sshKeyPair): void {
            $result = $cycleTimer->measure(
                'reset.restore-stateful.all',
                fn () => $host->restoreSnapshotsConcurrently(
                    array_map(
                        fn (IncusInstance $instance): string => $instance->name(),
                        array_values($instances),
                    ),
                    'lease-warm',
                    stateful: true,
                ),
            );

            if (! $result->successful()) {
                throw new \RuntimeException("Could not restore stateful topology snapshots: {$result->errorOutput()}");
            }

            foreach ($primaryUsers as $role => $primaryUser) {
                $instance = $instances[$role] ?? null;

                if ($instance === null) {
                    continue;
                }

                $cycleTimer->measure("reset.command-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
            }
        };
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function reestablishWireGuardRoutes(array $instances, DockerTopologyNetworkPlan $networkPlan): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();
        $controlWireGuardIp = $networkPlan->ipForRole('control');
        $gatewayWireGuardIp = $networkPlan->ipForRole('gateway');

        E2ENetwork::assignWireGuardIp($control, $controlWireGuardIp);
        E2ENetwork::assignWireGuardIp($gateway, $gatewayWireGuardIp);
        E2ENetwork::routeWireGuardPeer($control, $gatewayWireGuardIp, $gatewayIp, $controlWireGuardIp);
        E2ENetwork::routeWireGuardPeer($gateway, $controlWireGuardIp, $controlIp, $gatewayWireGuardIp);

        $this->routeAppPeer($gateway, $gatewayIp, $gatewayWireGuardIp, $instances['dev'] ?? null, $networkPlan->ipForRole('dev'));
        $this->routeAppPeer($gateway, $gatewayIp, $gatewayWireGuardIp, $instances['prod'] ?? null, $networkPlan->ipForRole('prod'));
    }

    private function routeAppPeer(IncusInstance $gateway, string $gatewayIp, string $gatewayWireGuardIp, ?IncusInstance $app, string $appWireGuardIp): void
    {
        if ($app === null) {
            return;
        }

        $appIp = $app->waitForIpv4();

        E2ENetwork::assignWireGuardIp($app, $appWireGuardIp);
        E2ENetwork::routeWireGuardPeer($gateway, $appWireGuardIp, $appIp, $gatewayWireGuardIp);
        E2ENetwork::routeWireGuardPeer($app, $gatewayWireGuardIp, $gatewayIp, $appWireGuardIp);
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function retargetTopology(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, DockerTopologyNetworkPlan $networkPlan): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $gatewayIp = $networkPlan->ipForRole('gateway');
        $controlIp = $networkPlan->ipForRole('control');

        E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
            'cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway %s --skip-runtime-install',
            escapeshellarg($gatewayIp),
        ), timeoutSeconds: 120);
        E2EGatewayApi::seedControlIdentity($gateway, $controlIp, $config->controlUser, $gatewayIp, $controlIp);

        $this->retargetControl($control, $config, $networkPlan, $sshKeyPair);

        if (isset($instances['dev'])) {
            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-dev-1 --role=app --host=%s --wireguard-address=%s --environment=development --tld=test --gateway-endpoint=%s --ssh-user=orbit --user=orbit',
                escapeshellarg($networkPlan->ipForRole('dev')),
                escapeshellarg($networkPlan->ipForRole('dev')),
                escapeshellarg($gatewayIp),
            ), timeoutSeconds: 120);
        }

        if (isset($instances['prod'])) {
            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-prod-1 --role=app --host=%s --wireguard-address=%s --environment=production --gateway-endpoint=%s --ssh-user=orbit --user=orbit',
                escapeshellarg($networkPlan->ipForRole('prod')),
                escapeshellarg($networkPlan->ipForRole('prod')),
                escapeshellarg($gatewayIp),
            ), timeoutSeconds: 120);
        }
    }

    private function retargetControl(IncusInstance $control, E2EConfig $config, DockerTopologyNetworkPlan $networkPlan, SshKeyPair $sshKeyPair): void
    {
        $gatewayIpValue = var_export($networkPlan->ipForRole('gateway'), true);

        $php = <<<PHP
\\App\\Models\\Node::query()->updateOrCreate(
    ['name' => 'gateway'],
    [
        'role' => 'gateway',
        'environment' => null,
        'tld' => null,
        'platform' => 'unknown',
        'host' => {$gatewayIpValue},
        'wireguard_address' => {$gatewayIpValue},
        'gateway_endpoint' => null,
        'ssh_user' => 'orbit',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'is_local' => false,
    ],
);

\$settings = \\App\\Models\\LocalGatewaySettings::current();
\$settings->fill([
    'gateway_url' => 'https://'.{$gatewayIpValue},
    'gateway_wg_ip' => {$gatewayIpValue},
]);
\$settings->save();
PHP;

        E2ECommand::ssh(
            $control,
            $config->controlUser,
            $sshKeyPair,
            'cd /home/'.$config->controlUser.'/orbit && php artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 120,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function waitForPeerRoutes(array $instances, DockerTopologyNetworkPlan $networkPlan): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        foreach (['dev', 'prod'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $this->waitForGatewaySsh($gateway, $networkPlan->ipForRole($role));
        }
    }

    private function waitForGatewaySsh(IncusInstance $gateway, string $wireGuardIp): void
    {
        E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            sprintf(
                'deadline=$((SECONDS+60)); until ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=5 orbit@%s true; do if [ "$SECONDS" -ge "$deadline" ]; then exit 1; fi; sleep 2; done',
                escapeshellarg($wireGuardIp),
            ),
            timeoutSeconds: 75,
        );
    }

    private function createSshKeyPair(IncusHost $host, string $runId): SshKeyPair
    {
        $workDirectory = "/tmp/orbit-e2e-topology-{$runId}";

        $result = $host->run(sprintf(
            'rm -rf %s && mkdir -p %s',
            escapeshellarg($workDirectory),
            escapeshellarg($workDirectory),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not create topology work directory: {$result->errorOutput()}");
        }

        $privateKeyPath = "{$workDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";

        $result = $host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$runId}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not create E2E SSH key pair: {$result->errorOutput()}");
        }

        return new SshKeyPair($privateKeyPath, $publicKeyPath);
    }
}
