<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\WireGuard\WireGuardKeyGenerator;

final readonly class IncusTopologyProvider implements E2ETopologyProvider
{
    private const string GatewayWireGuardIp = '10.6.0.2';

    private const string ControlWireGuardIp = '10.6.0.3';

    private const string DevWireGuardIp = '10.6.0.4';

    private const string ProdWireGuardIp = '10.6.0.5';

    private const string AgentWireGuardIp = '10.6.0.6';

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

        if ($host === null) {
            throw new \RuntimeException("Prepared topology {$kind->value} is not available on any Incus host");
        }

        $instances = IncusTopologyTemplate::clone($host, $kind, $runId, $timer);

        $sshKeyPair = $this->createSshKeyPair($host, $runId);
        $primaryUsers = $this->prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options);
        $snapshotReset = $this->prepareSnapshotReset($host, $instances, $primaryUsers, $sshKeyPair, $timer, $options->startGatewayApi);

        $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $sshKeyPair, $options): array {
            $newInstances = IncusTopologyTemplate::clone($host, $kind, $runId, $cycleTimer);
            $newPrimaryUsers = $this->prepareInstances($newInstances, $this->config, $sshKeyPair, $cycleTimer, $options);

            return [
                'instances' => $newInstances,
                'snapshotReset' => $this->prepareSnapshotReset($host, $newInstances, $newPrimaryUsers, $sshKeyPair, $cycleTimer, $options->startGatewayApi),
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
            gatewayApiIp: self::GatewayWireGuardIp,
            agent: $instances['agent'] ?? null,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return array<string, string>
     */
    private function prepareInstances(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): array
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

        $timer->measure('known-hosts', fn () => $this->clearKnownHosts($instances));
        $timer->measure('wireguard', fn () => $this->retargetRealWireGuard($instances));
        $timer->measure('retarget', fn () => $this->retargetTopology($instances, $config, $sshKeyPair));
        $timer->measure('network-ready', fn () => $this->waitForPeerRoutes($instances));

        if ($options->startGatewayApi && isset($instances['gateway'])) {
            $timer->measure('gateway-api.start', fn () => E2EGatewayApi::start(
                $instances['gateway'],
                'topology-lease',
                gatewayIp: self::GatewayWireGuardIp,
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
    private function prepareSnapshotReset(IncusHost $host, array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer, bool $startGatewayApi): ?\Closure
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
            : $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair, $startGatewayApi);
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

    private function snapshotResetFor(array $instances, array $primaryUsers, SshKeyPair $sshKeyPair, bool $startGatewayApi): \Closure
    {
        return function (E2EPhaseTimer $cycleTimer) use ($instances, $primaryUsers, $sshKeyPair, $startGatewayApi): void {
            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
                $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
                $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
            }

            foreach ($instances as $role => $instance) {
                $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
            }

            $cycleTimer->measure('reset.wireguard', fn () => $this->retargetRealWireGuard($instances));
            $cycleTimer->measure('reset.retarget', fn () => $this->retargetTopology($instances, $this->config, $sshKeyPair));
            $cycleTimer->measure('reset.network-ready', fn () => $this->waitForPeerRoutes($instances));

            if ($startGatewayApi && isset($instances['gateway'])) {
                $cycleTimer->measure('reset.gateway-api.start', fn () => E2EGatewayApi::start(
                    $instances['gateway'],
                    'topology-reset',
                    gatewayIp: self::GatewayWireGuardIp,
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
    private function retargetRealWireGuard(array $instances): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $gatewayProviderIp = $gateway->waitForIpv4();
        $wgEasy = new E2EWgEasyGateway;
        $wgEasy->start($gateway, $gatewayProviderIp);

        $mesh = $this->meshFor($instances, $gatewayProviderIp);
        $wgEasy->configurePeers($gateway, $mesh->wgEasyPeers());

        foreach (['gateway', 'control', 'dev', 'prod', 'agent'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $mesh->installRole($instances[$role], $role);
        }

        $mesh->verifyRole($gateway, 'gateway', array_values(array_filter([
            'control',
            isset($instances['dev']) ? 'dev' : null,
            isset($instances['prod']) ? 'prod' : null,
            isset($instances['agent']) ? 'agent' : null,
        ])));
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function meshFor(array $instances, string $gatewayProviderIp): E2EWireGuardMesh
    {
        $generator = app(WireGuardKeyGenerator::class);
        $gatewayHost = $generator->generateKeyPair();
        $control = $generator->generateKeyPair();
        $dev = isset($instances['dev']) ? $generator->generateKeyPair() : null;
        $prod = isset($instances['prod']) ? $generator->generateKeyPair() : null;
        $agent = isset($instances['agent']) ? $generator->generateKeyPair() : null;
        $wgEasyPublicKey = trim($instances['gateway']->exec('docker exec wg-easy wg show wg0 public-key')->output());

        return E2EWireGuardMesh::standard(
            gatewayProviderIp: $gatewayProviderIp,
            wgEasyPublicKey: $wgEasyPublicKey,
            gatewayHostPrivateKey: $gatewayHost['private_key'],
            gatewayHostPublicKey: $gatewayHost['public_key'],
            controlPrivateKey: $control['private_key'],
            controlPublicKey: $control['public_key'],
            devPrivateKey: $dev['private_key'] ?? null,
            devPublicKey: $dev['public_key'] ?? null,
            prodPrivateKey: $prod['private_key'] ?? null,
            prodPublicKey: $prod['public_key'] ?? null,
            agentPrivateKey: $agent['private_key'] ?? null,
            agentPublicKey: $agent['public_key'] ?? null,
        );
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     */
    private function retargetTopology(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
            'cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway %s --skip-runtime-install',
            escapeshellarg(self::GatewayWireGuardIp),
        ), timeoutSeconds: 120);
        E2EGatewayApi::seedControlIdentity($gateway, self::ControlWireGuardIp, $config->controlUser);

        $this->retargetControl($control, $config, $sshKeyPair);

        if (isset($instances['dev'])) {
            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-dev-1 --role=app --host=%s --wireguard-address=%s --environment=development --tld=test --gateway-endpoint=%s --user=orbit --user=orbit',
                escapeshellarg(self::DevWireGuardIp),
                escapeshellarg(self::DevWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 120);
        }

        if (isset($instances['prod'])) {
            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-prod-1 --role=app --host=%s --wireguard-address=%s --environment=production --gateway-endpoint=%s --user=orbit --user=orbit',
                escapeshellarg(self::ProdWireGuardIp),
                escapeshellarg(self::ProdWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 120);
        }

        if (isset($instances['agent'])) {
            E2ECommand::ssh($gateway, 'orbit', $sshKeyPair, sprintf(
                'cd /home/orbit/orbit && php artisan orbit:internal:bake-agent-node agent-1 --host=%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit --tld=agent',
                escapeshellarg(self::AgentWireGuardIp),
                escapeshellarg(self::AgentWireGuardIp),
                escapeshellarg(self::GatewayWireGuardIp),
            ), timeoutSeconds: 120);
        }
    }

    private function retargetControl(IncusInstance $control, E2EConfig $config, SshKeyPair $sshKeyPair): void
    {
        $gatewayIpValue = var_export(self::GatewayWireGuardIp, true);

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
                'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
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
    private function waitForPeerRoutes(array $instances): void
    {
        $gateway = $instances['gateway'] ?? null;

        if ($gateway === null) {
            return;
        }

        foreach (['dev', 'prod'] as $role) {
            if (! isset($instances[$role])) {
                continue;
            }

            $this->waitForGatewaySsh($gateway, $this->wireGuardIpForRole($role));
        }
    }

    /**
     * Clones inherit `~/.ssh/known_hosts` from their templates. Templates pick
     * up stale entries from earlier bake-time SSHes (e.g. control bootstrapping
     * dev/prod through their provider IPs), and Incus reuses provider IPs
     * across runs, so the clone IPs collide with stale fingerprints and trip
     * StrictHostKeyChecking inside production SSH paths.
     *
     * Wipe per-user known_hosts on every leased clone so the lease starts with
     * an empty trust file. Future SSHes use `StrictHostKeyChecking=accept-new`
     * and repopulate cleanly.
     *
     * @param  array<string, IncusInstance>  $instances
     */
    private function clearKnownHosts(array $instances): void
    {
        foreach ($instances as $instance) {
            $instance->exec(
                'for d in /root /home/*; do '
                    .'[ -d "$d/.ssh" ] || continue; '
                    .'rm -f "$d/.ssh/known_hosts" "$d/.ssh/known_hosts.old"; '
                .'done',
                timeoutSeconds: 30,
            );
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

    private function wireGuardIpForRole(string $role): string
    {
        return match ($role) {
            'gateway' => self::GatewayWireGuardIp,
            'control' => self::ControlWireGuardIp,
            'dev' => self::DevWireGuardIp,
            'prod' => self::ProdWireGuardIp,
            default => throw new \RuntimeException("Unknown topology role [{$role}]."),
        };
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
