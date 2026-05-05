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

    public function __construct(
        protected readonly IncusHost $host,
    ) {}

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
    public function build(E2ETopologyKind $kind): array
    {
        $this->validatePreFlight($kind);

        $workDirectory = $this->createWorkDirectory();

        try {
            $key = $this->createSshKeyPair($workDirectory);
            $instances = $this->provisionInstances($kind, $key);

            return $this->finalizeInstances($instances);
        } finally {
            $this->host->run('rm -rf '.escapeshellarg($workDirectory));
        }
    }

    private function validatePreFlight(E2ETopologyKind $kind): void
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

            if ($this->host->instanceExists($name)) {
                throw new RuntimeException("Template instance [{$name}] already exists.");
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
        $this->copyAndStart($baseImage, $controlName);
        $control = new IncusInstance($this->host, $controlName);
        $control->waitForAgent();
        $this->host->waitForCloudInit($controlName);
        $control->waitForAgent();
        $this->host->provisionInstance($controlName, 'control', (string) $this->remoteBundleDir, $this->host->config->controlUser);
        $control->authorizeSsh($this->host->config->controlUser, $key);
        $control->waitForSsh($this->host->config->controlUser, $key);
        $controlIp = $control->waitForIpv4();
        E2EControlIdentity::ensure($control, $this->host->config->controlUser, $key);
        $instances['control'] = $control;

        if (in_array('gateway', $roles, true)) {
            $gatewayName = IncusTopologyTemplate::templateName($kind, 'gateway');
            $this->copyAndStart($baseImage, $gatewayName);
            $gateway = new IncusInstance($this->host, $gatewayName);
            $gateway->waitForAgent();
            $this->host->waitForCloudInit($gatewayName);
            $gateway->waitForAgent();
            $this->host->provisionInstance($gatewayName, 'gateway', (string) $this->remoteBundleDir);
            $gateway->authorizeSsh('orbit', $key);
            $gateway->waitForSsh('orbit', $key);
            $gatewayIp = $gateway->waitForIpv4();
            $instances['gateway'] = $gateway;

            E2ENetwork::assignWireGuardIp($control, self::ControlWireGuardIp);
            E2ENetwork::assignWireGuardIp($gateway, self::GatewayWireGuardIp);
            E2ENetwork::routeWireGuardPeer($control, self::GatewayWireGuardIp, $gatewayIp, self::ControlWireGuardIp);
            E2ENetwork::routeWireGuardPeer($gateway, self::ControlWireGuardIp, $controlIp, self::GatewayWireGuardIp);

            E2EGatewayApi::seedControlIdentity($gateway, $controlIp, $this->host->config->controlUser);
            E2EGatewayApi::installRootSshKey($gateway, $key);
            E2EGatewayApi::start($gateway, 'topology-build');
            E2EGatewayApi::waitForGatewayApi($control, $this->host->config->controlUser, $key);

            E2ECommand::ssh(
                $control,
                $this->host->config->controlUser,
                $key,
                'cd /home/'.$this->host->config->controlUser.'/orbit && orbit gateway:add '.self::GatewayWireGuardIp.' --json',
                timeoutSeconds: 600,
            );
        }

        if (in_array('dev', $roles, true)) {
            if ($gateway === null) {
                throw new RuntimeException('Cannot prepare dev app without a gateway instance.');
            }

            $devName = IncusTopologyTemplate::templateName($kind, 'dev');
            $this->copyAndStart($baseImage, $devName);
            $dev = new IncusInstance($this->host, $devName);
            $dev->waitForAgent();
            $this->host->waitForCloudInit($devName);
            $dev->waitForAgent();
            $this->host->provisionInstance($devName, 'app', (string) $this->remoteBundleDir);
            $dev->authorizeSsh('orbit', $key);
            $dev->waitForSsh('orbit', $key);
            $devIp = $dev->waitForIpv4();
            $instances['dev'] = $dev;

            E2ENetwork::assignWireGuardIp($dev, self::DevWireGuardIp);

            $this->bakeAppNode(
                $control,
                $gateway,
                $key,
                'app-dev-1',
                $devIp,
                self::DevWireGuardIp,
                'development',
                'test',
            );
        }

        if (in_array('prod', $roles, true)) {
            if ($gateway === null) {
                throw new RuntimeException('Cannot prepare prod app without a gateway instance.');
            }

            $prodName = IncusTopologyTemplate::templateName($kind, 'prod');
            $this->copyAndStart($baseImage, $prodName);
            $prod = new IncusInstance($this->host, $prodName);
            $prod->waitForAgent();
            $this->host->waitForCloudInit($prodName);
            $prod->waitForAgent();
            $this->host->provisionInstance($prodName, 'app', (string) $this->remoteBundleDir);
            $prod->authorizeSsh('orbit', $key);
            $prod->waitForSsh('orbit', $key);
            $prodIp = $prod->waitForIpv4();
            $instances['prod'] = $prod;

            E2ENetwork::assignWireGuardIp($prod, self::ProdWireGuardIp);

            $this->bakeAppNode(
                $control,
                $gateway,
                $key,
                'app-prod-1',
                $prodIp,
                self::ProdWireGuardIp,
                'production',
            );
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

            $result = $this->host->stopInstance($name);
            if (! $result->successful()) {
                throw new RuntimeException("Could not stop {$name}: {$result->errorOutput()}");
            }

            $result = $this->host->snapshotInstance($name, 'clean');
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
