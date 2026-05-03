<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use RuntimeException;

class IncusTopologyBuilder
{
    public function __construct(
        protected readonly IncusHost $host,
    ) {}

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
        $images = match ($kind) {
            E2ETopologyKind::Control => [$this->host->config->controlImage],
            E2ETopologyKind::ControlGateway => [$this->host->config->controlImage, $this->host->config->gatewayImage],
            E2ETopologyKind::ControlGatewayDev => [$this->host->config->controlImage, $this->host->config->gatewayImage, $this->host->config->blankImage],
            E2ETopologyKind::ControlGatewayDevProd => [$this->host->config->controlImage, $this->host->config->gatewayImage, $this->host->config->blankImage],
        };

        foreach ($images as $image) {
            if (! $this->host->imageExists($image)) {
                throw new RuntimeException("Required source image [{$image}] not found on host.");
            }
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

        $controlName = IncusTopologyTemplate::templateName($kind, 'control');
        $this->copyAndStart($this->host->config->controlImage, $controlName);
        $control = new IncusInstance($this->host, $controlName);
        $control->waitForAgent();
        $control->authorizeSsh($this->host->config->controlUser, $key);
        $control->waitForSsh($this->host->config->controlUser, $key);
        $controlIp = $control->waitForIpv4();
        $instances['control'] = $control;

        if (in_array('gateway', $roles, true)) {
            $gatewayName = IncusTopologyTemplate::templateName($kind, 'gateway');
            $this->copyAndStart($this->host->config->gatewayImage, $gatewayName);
            $gateway = new IncusInstance($this->host, $gatewayName);
            $gateway->waitForAgent();
            $gateway->authorizeSsh('orbit', $key);
            $gateway->waitForSsh('orbit', $key);
            $gatewayIp = $gateway->waitForIpv4();
            $instances['gateway'] = $gateway;

            E2ENetwork::assignWireGuardIp($control, '10.6.0.3');
            E2ENetwork::assignWireGuardIp($gateway, '10.6.0.2');
            E2ENetwork::routeWireGuardPeer($control, '10.6.0.2', $gatewayIp, '10.6.0.3');
            E2ENetwork::routeWireGuardPeer($gateway, '10.6.0.3', $controlIp, '10.6.0.2');

            E2EGatewayApi::seedControlIdentity($gateway, $controlIp, $this->host->config->controlUser);
            E2EGatewayApi::installRootSshKey($gateway, $key);
            E2EGatewayApi::start($gateway, 'topology-build');
            E2EGatewayApi::waitForGatewayApi($control, $this->host->config->controlUser, $key);

            E2ECommand::ssh(
                $control,
                $this->host->config->controlUser,
                $key,
                "cd /home/{$this->host->config->controlUser}/orbit && orbit gateway:add 10.6.0.2 --json",
                timeoutSeconds: 600,
            );
        }

        if (in_array('dev', $roles, true)) {
            $devName = IncusTopologyTemplate::templateName($kind, 'dev');
            $this->copyAndStart($this->host->config->blankImage, $devName);
            $dev = new IncusInstance($this->host, $devName);
            $dev->waitForAgent();
            $dev->authorizeSsh($this->host->config->bootstrapUser, $key);
            $dev->waitForSsh($this->host->config->bootstrapUser, $key);
            $devIp = $dev->waitForIpv4();
            $instances['dev'] = $dev;

            E2ECommand::ssh(
                $control,
                $this->host->config->controlUser,
                $key,
                "cd /home/{$this->host->config->controlUser}/orbit && orbit node:new app-dev-1 --role=app --host={$devIp} --environment=development --tld=test --ssh-user={$this->host->config->bootstrapUser} --json",
                timeoutSeconds: 1800,
            );
        }

        if (in_array('prod', $roles, true)) {
            $prodName = IncusTopologyTemplate::templateName($kind, 'prod');
            $this->copyAndStart($this->host->config->blankImage, $prodName);
            $prod = new IncusInstance($this->host, $prodName);
            $prod->waitForAgent();
            $prod->authorizeSsh($this->host->config->bootstrapUser, $key);
            $prod->waitForSsh($this->host->config->bootstrapUser, $key);
            $prodIp = $prod->waitForIpv4();
            $instances['prod'] = $prod;

            E2ECommand::ssh(
                $control,
                $this->host->config->controlUser,
                $key,
                "cd /home/{$this->host->config->controlUser}/orbit && orbit node:new app-prod-1 --role=app --host={$prodIp} --environment=production --ssh-user={$this->host->config->bootstrapUser} --json",
                timeoutSeconds: 1800,
            );
        }

        return $instances;
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
        $result = $this->host->run(sprintf(
            'incus launch %s %s --vm >/dev/null',
            escapeshellarg($sourceImageAlias),
            escapeshellarg($target),
        ), timeoutSeconds: $this->host->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not launch {$target} from {$sourceImageAlias}: {$result->errorOutput()}");
        }
    }
}
