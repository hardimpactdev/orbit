<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

class IncusTopologyBuilder
{
    private ?string $remoteBundleDir = null;

    private const string GatewayWireGuardIp = '10.6.0.2';

    private const string ControlWireGuardIp = '10.6.0.3';

    private const string DevWireGuardIp = '10.6.0.4';

    private const string ProdWireGuardIp = '10.6.0.5';

    private readonly E2EPhaseTimer $timer;

    public function __construct(
        protected readonly IncusHost $host,
        ?E2EPhaseTimer $timer = null,
    ) {
        $this->timer = $timer ?? new E2EPhaseTimer;
    }

    /**
     * Stage a remote bundle directory that the builder will use to provision
     * each role instance via bin/e2e-provision-node. Must be set before build()
     * for the new base-image lane; legacy callers that do not set it fall back
     * to assuming the bundle has already been prepared by the topology command.
     */
    public function useBundle(string $remoteBundleDir): void
    {
        $this->remoteBundleDir = $remoteBundleDir;
    }

    /**
     * Build all role instances for a topology kind, snapshot each as `clean`,
     * and return the list of templates produced.
     *
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    public function build(E2ETopologyKind $kind, bool $replaceExisting = false): array
    {
        $this->timer->measure('preflight', fn () => $this->validatePreFlight($kind, $replaceExisting));

        $workDirectory = $this->timer->measure('workdir', fn (): string => $this->createWorkDirectory());

        try {
            $key = $this->timer->measure('ssh-key', fn (): SshKeyPair => $this->createSshKeyPair($workDirectory));
            $instances = $this->provisionInstances($kind, $key);

            return $this->finalizeInstances($instances);
        } finally {
            $this->timer->measure('workdir.cleanup', fn () => $this->host->run('rm -rf '.escapeshellarg((string) $workDirectory)));
        }
    }

    private function validatePreFlight(E2ETopologyKind $kind, bool $replaceExisting): void
    {
        $baseImage = $this->host->config->baseImage;

        if (! $this->host->imageExists($baseImage)) {
            throw new RuntimeException("Required source image [{$baseImage}] not found on host.");
        }

        if ($this->remoteBundleDir === null) {
            throw new RuntimeException(
                'No provisioning bundle has been staged. Call useBundle() before build().'
            );
        }

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            $name = IncusTopologyTemplate::templateName($kind, $role);

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
     * @return array<string, IncusInstance>
     */
    private function provisionInstances(E2ETopologyKind $kind, SshKeyPair $key): array
    {
        $instances = [];
        $roles = IncusTopologyTemplate::rolesFor($kind);
        $baseImage = $this->host->config->baseImage;
        $gateway = null;

        $controlName = IncusTopologyTemplate::templateName($kind, 'control');
        $this->timer->measure('control.launch', fn () => $this->copyAndStart($baseImage, $controlName));
        $control = new IncusInstance($this->host, $controlName);
        $this->timer->measure('control.agent.initial', fn () => $control->waitForAgent());
        $this->timer->measure('control.cloud-init', fn () => $this->host->waitForCloudInit($controlName));
        $this->timer->measure('control.agent.after-cloud-init', fn () => $control->waitForAgent());
        $this->timer->measure('control.provision', fn () => $this->host->provisionInstance($controlName, 'control', (string) $this->remoteBundleDir, $this->host->config->controlUser));
        $this->timer->measure('control.ssh-authorize', fn () => $control->authorizeSsh($this->host->config->controlUser, $key));
        $this->timer->measure('control.ssh-ready', fn () => $control->waitForSsh($this->host->config->controlUser, $key));
        $controlIp = $this->timer->measure('control.ipv4', fn (): string => $control->waitForIpv4());
        $this->timer->measure('control.identity', fn () => E2EControlIdentity::ensure($control, $this->host->config->controlUser, $key));
        $instances['control'] = $control;

        if (in_array('gateway', $roles, true)) {
            $gatewayName = IncusTopologyTemplate::templateName($kind, 'gateway');
            $this->timer->measure('gateway.launch', fn () => $this->copyAndStart($baseImage, $gatewayName));
            $gateway = new IncusInstance($this->host, $gatewayName);
            $this->timer->measure('gateway.agent.initial', fn () => $gateway->waitForAgent());
            $this->timer->measure('gateway.cloud-init', fn () => $this->host->waitForCloudInit($gatewayName));
            $this->timer->measure('gateway.agent.after-cloud-init', fn () => $gateway->waitForAgent());
            $this->timer->measure('gateway.provision', fn () => $this->host->provisionInstance($gatewayName, 'gateway', (string) $this->remoteBundleDir));
            $this->timer->measure('gateway.ssh-authorize', fn () => $gateway->authorizeSsh('orbit', $key));
            $this->timer->measure('gateway.ssh-ready', fn () => $gateway->waitForSsh('orbit', $key));
            $gatewayIp = $this->timer->measure('gateway.ipv4', fn (): string => $gateway->waitForIpv4());
            $instances['gateway'] = $gateway;

            $this->timer->measure('wireguard.control-gateway', function () use ($control, $controlIp, $gateway, $gatewayIp): void {
                E2ENetwork::assignWireGuardIp($control, self::ControlWireGuardIp);
                E2ENetwork::assignWireGuardIp($gateway, self::GatewayWireGuardIp);
                E2ENetwork::routeWireGuardPeer($control, self::GatewayWireGuardIp, $gatewayIp, self::ControlWireGuardIp);
                E2ENetwork::routeWireGuardPeer($gateway, self::ControlWireGuardIp, $controlIp, self::GatewayWireGuardIp);
            });

            $this->timer->measure('gateway.identity', fn () => E2EGatewayApi::seedControlIdentity($gateway, $controlIp, $this->host->config->controlUser));
            $this->timer->measure('gateway.root-ssh-key', fn () => E2EGatewayApi::installRootSshKey($gateway, $key));
            $this->timer->measure('gateway.api.start', fn () => E2EGatewayApi::start($gateway, 'topology-build'));
            $this->timer->measure('gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($control, $this->host->config->controlUser, $key));

            $this->timer->measure('gateway.register', fn () => E2ECommand::ssh(
                $control,
                $this->host->config->controlUser,
                $key,
                'cd /home/'.$this->host->config->controlUser.'/orbit && orbit gateway:add '.self::GatewayWireGuardIp.' --json',
                timeoutSeconds: 600,
            ));
        }

        if (in_array('dev', $roles, true)) {
            if ($gateway === null) {
                throw new RuntimeException('Cannot prepare dev app without a gateway instance.');
            }

            $devName = IncusTopologyTemplate::templateName($kind, 'dev');
            $this->timer->measure('dev.launch', fn () => $this->copyAndStart($baseImage, $devName));
            $dev = new IncusInstance($this->host, $devName);
            $this->timer->measure('dev.agent.initial', fn () => $dev->waitForAgent());
            $this->timer->measure('dev.cloud-init', fn () => $this->host->waitForCloudInit($devName));
            $this->timer->measure('dev.agent.after-cloud-init', fn () => $dev->waitForAgent());
            $this->timer->measure('dev.provision', fn () => $this->host->provisionInstance($devName, 'app', (string) $this->remoteBundleDir));
            $this->timer->measure('dev.ssh-authorize', fn () => $dev->authorizeSsh('orbit', $key));
            $this->timer->measure('dev.ssh-ready', fn () => $dev->waitForSsh('orbit', $key));
            $devIp = $this->timer->measure('dev.ipv4', fn (): string => $dev->waitForIpv4());
            $instances['dev'] = $dev;

            $this->timer->measure('dev.wireguard', fn () => E2ENetwork::assignWireGuardIp($dev, self::DevWireGuardIp));

            $this->timer->measure('dev.bake-node', fn () => $this->bakeAppNode(
                $control,
                $gateway,
                $key,
                'app-dev-1',
                $devIp,
                self::DevWireGuardIp,
                'development',
                'test',
            ));
        }

        if (in_array('prod', $roles, true)) {
            if ($gateway === null) {
                throw new RuntimeException('Cannot prepare prod app without a gateway instance.');
            }

            $prodName = IncusTopologyTemplate::templateName($kind, 'prod');
            $this->timer->measure('prod.launch', fn () => $this->copyAndStart($baseImage, $prodName));
            $prod = new IncusInstance($this->host, $prodName);
            $this->timer->measure('prod.agent.initial', fn () => $prod->waitForAgent());
            $this->timer->measure('prod.cloud-init', fn () => $this->host->waitForCloudInit($prodName));
            $this->timer->measure('prod.agent.after-cloud-init', fn () => $prod->waitForAgent());
            $this->timer->measure('prod.provision', fn () => $this->host->provisionInstance($prodName, 'app', (string) $this->remoteBundleDir));
            $this->timer->measure('prod.ssh-authorize', fn () => $prod->authorizeSsh('orbit', $key));
            $this->timer->measure('prod.ssh-ready', fn () => $prod->waitForSsh('orbit', $key));
            $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $prod->waitForIpv4());
            $instances['prod'] = $prod;

            $this->timer->measure('prod.wireguard', fn () => E2ENetwork::assignWireGuardIp($prod, self::ProdWireGuardIp));

            $this->timer->measure('prod.bake-node', fn () => $this->bakeAppNode(
                $control,
                $gateway,
                $key,
                'app-prod-1',
                $prodIp,
                self::ProdWireGuardIp,
                'production',
            ));
        }

        return $instances;
    }

    private function bakeAppNode(
        IncusInstance $control,
        IncusInstance $gateway,
        SshKeyPair $key,
        string $name,
        string $host,
        string $wireGuardAddress,
        string $environment,
        ?string $tld = null,
    ): void {
        $parts = [
            'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node',
            escapeshellarg($name),
            '--role=app',
            '--host='.escapeshellarg($host),
            '--wireguard-address='.escapeshellarg($wireGuardAddress),
            '--environment='.escapeshellarg($environment),
            '--gateway-endpoint='.self::GatewayWireGuardIp,
            '--ssh-user=orbit',
            '--user=orbit',
        ];

        if ($tld !== null) {
            $parts[] = '--tld='.escapeshellarg($tld);
        }

        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            implode(' ', $parts),
            timeoutSeconds: 120,
        );

        E2EGatewayApi::waitForGatewayApi($control, $this->host->config->controlUser, $key);
    }

    /**
     * @param  array<string, IncusInstance>  $instances
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    private function finalizeInstances(array $instances): array
    {
        $manifest = [];

        foreach ($instances as $role => $instance) {
            $name = $instance->name();

            $result = $this->timer->measure("finalize.stop.{$role}", fn () => $this->host->stopInstance($name));
            if (! $result->successful()) {
                throw new RuntimeException("Could not stop {$name}: {$result->errorOutput()}");
            }

            $result = $this->timer->measure("finalize.snapshot.{$role}", fn () => $this->host->snapshotInstance($name, 'clean'));
            if (! $result->successful()) {
                throw new RuntimeException("Could not snapshot {$name}: {$result->errorOutput()}");
            }

            $manifest[] = [
                'role' => $role,
                'name' => $name,
                'snapshot' => 'clean',
            ];
        }

        return $manifest;
    }

    private function copyAndStart(string $sourceImageAlias, string $target): void
    {
        $result = $this->host->launchInstance($sourceImageAlias, $target, timeoutSeconds: $this->host->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not launch {$target} from {$sourceImageAlias}: {$result->errorOutput()}");
        }
    }
}
