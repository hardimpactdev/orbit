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
     * Stage a remote bundle directory that the builder will use to install the
     * control node. Gateway and app roles are then provisioned through node:new
     * from that control node.
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
        $this->timer->measure('preflight', fn () => $this->validatePreFlight($kind, $replaceExisting));

        $workDirectory = $this->timer->measure('workdir', fn (): string => $this->createWorkDirectory());

        try {
            $key = $this->timer->measure('ssh-key', fn (): SshKeyPair => $this->createSshKeyPair($workDirectory));
            $manifests = $this->buildStages($kind, $key);

            return $manifests[$kind->value];
        } finally {
            $this->timer->measure('workdir.cleanup', fn () => $this->host->run('rm -rf '.escapeshellarg((string) $workDirectory)));
        }
    }

    private function validatePreFlight(E2ETopologyKind $kind, bool $replaceExisting): void
    {
        $blankImage = $this->host->config->blankImage;

        if (! $this->host->imageExists($blankImage)) {
            throw new RuntimeException("Required blank image [{$blankImage}] not found on host.");
        }

        if ($this->remoteBundleDir === null) {
            throw new RuntimeException(
                'No provisioning bundle has been staged. Call useBundle() before build().'
            );
        }

        foreach ($this->templateNamesForRefresh($kind, includeLegacyNames: $replaceExisting) as $name) {
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
     * @return array<string, list<array{role: string, name: string, snapshot: string}>>
     */
    private function buildStages(E2ETopologyKind $target, SshKeyPair $key): array
    {
        $manifests = [];
        $instances = [];

        foreach ($this->stagesThrough($target) as $stage) {
            $instances = match ($stage) {
                E2ETopologyKind::Control => $this->buildControlStage($key),
                E2ETopologyKind::ControlGateway => $this->buildGatewayStage($key),
                E2ETopologyKind::ControlGatewayDev => $this->buildDevelopmentAppStage($key),
                E2ETopologyKind::ControlGatewayDevProd => $this->buildProductionAppStage($key),
            };

            $manifests[$stage->value] = $this->finalizeInstances($stage, $instances);
        }

        return $manifests;
    }

    /**
     * @return list<E2ETopologyKind>
     */
    private function stagesThrough(E2ETopologyKind $target): array
    {
        $stages = [
            E2ETopologyKind::Control,
            E2ETopologyKind::ControlGateway,
            E2ETopologyKind::ControlGatewayDev,
            E2ETopologyKind::ControlGatewayDevProd,
        ];

        $targetIndex = array_search($target, $stages, true);

        if ($targetIndex === false) {
            throw new RuntimeException("Unsupported topology kind [{$target->value}].");
        }

        return array_slice($stages, 0, $targetIndex + 1);
    }

    /**
     * @return list<string>
     */
    private function templateNamesForRefresh(E2ETopologyKind $kind, bool $includeLegacyNames): array
    {
        $names = [];

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            $names[] = IncusTopologyTemplate::templateName($kind, $role);
        }

        if (! $includeLegacyNames) {
            return array_reverse(array_values(array_unique($names)));
        }

        foreach ($this->stagesThrough($kind) as $stage) {
            foreach (IncusTopologyTemplate::rolesFor($stage) as $role) {
                $names[] = "orbit-template-{$stage->value}-{$role}";
            }
        }

        return array_reverse(array_values(array_unique($names)));
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildControlStage(SshKeyPair $key): array
    {
        $instances = [];
        $controlName = IncusTopologyTemplate::templateName(E2ETopologyKind::Control, 'control');
        $this->timer->measure('control.launch', fn () => $this->launchBlank($controlName));
        $control = new IncusInstance($this->host, $controlName);
        $this->timer->measure('control.agent.initial', fn () => $control->waitForAgent());
        $this->timer->measure('control.cloud-init', fn () => $this->host->waitForCloudInit($controlName));
        $this->timer->measure('control.agent.after-cloud-init', fn () => $control->waitForAgent());
        $this->timer->measure('control.provision', fn () => $this->host->provisionInstance($controlName, 'control', (string) $this->remoteBundleDir, $this->host->config->controlUser));
        $this->timer->measure('control.ssh-authorize', fn () => $control->authorizeSsh($this->host->config->controlUser, $key));
        $this->timer->measure('control.ssh-ready', fn () => $control->waitForSsh($this->host->config->controlUser, $key));
        $this->timer->measure('control.ipv4', fn (): string => $control->waitForIpv4());
        $this->timer->measure('control.provisioning-ssh-key', fn () => $this->installPrivateSshKey($control, $key, $this->host->config->controlUser));
        $this->timer->measure('control.identity', fn () => E2EControlIdentity::ensure($control, $this->host->config->controlUser, $key));
        $instances['control'] = $control;

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildGatewayStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['control'], $key);
        $control = $instances['control'];
        $gateway = $this->launchBlankRole('gateway', $key);
        $gatewayIp = $this->timer->measure('gateway.ipv4', fn (): string => $gateway->waitForIpv4());
        $instances['gateway'] = $gateway;

        $this->timer->measure('gateway.node-new', fn () => $this->runGatewayNodeNew($control, $key, $gatewayIp));
        $this->timer->measure('gateway.use-wireguard-url', fn () => $this->useWireGuardGatewayUrl($control, $key));
        $this->timer->measure('gateway.wireguard-routes', fn () => $this->reestablishWireGuardRoutes($instances));
        $this->timer->measure('gateway.provisioning-ssh-key', fn () => E2EGatewayApi::installProvisioningSshKey($gateway, $key));
        $this->timer->measure('gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($control, $this->host->config->controlUser, $key));

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildDevelopmentAppStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['control', 'gateway'], $key);

        $this->timer->measure('dev.wireguard-routes', fn () => $this->reestablishWireGuardRoutes($instances));
        $this->timer->measure('dev.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['control'], $this->host->config->controlUser, $key));

        $dev = $this->launchBlankRole('dev', $key);
        $devIp = $this->timer->measure('dev.ipv4', fn (): string => $dev->waitForIpv4());
        $instances['dev'] = $dev;

        $this->timer->measure('dev.node-new', fn () => $this->runAppNodeNew(
            $instances['control'],
            $key,
            'app-dev-1',
            $devIp,
            'development',
            'test',
        ));

        return $instances;
    }

    /**
     * @return array<string, IncusInstance>
     */
    private function buildProductionAppStage(SshKeyPair $key): array
    {
        $instances = $this->startTemplateRoles(['control', 'gateway', 'dev'], $key);

        $this->timer->measure('prod.wireguard-routes', fn () => $this->reestablishWireGuardRoutes($instances));
        $this->timer->measure('prod.gateway.api.ready', fn () => E2EGatewayApi::waitForGatewayApi($instances['control'], $this->host->config->controlUser, $key));

        $prod = $this->launchBlankRole('prod', $key);
        $prodIp = $this->timer->measure('prod.ipv4', fn (): string => $prod->waitForIpv4());
        $instances['prod'] = $prod;

        $this->timer->measure('prod.node-new', fn () => $this->runAppNodeNew(
            $instances['control'],
            $key,
            'app-prod-1',
            $prodIp,
            'production',
        ));

        return $instances;
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, IncusInstance>
     */
    private function startTemplateRoles(array $roles, SshKeyPair $key): array
    {
        $instances = [];

        foreach ($roles as $role) {
            $instances[$role] = $this->startTemplateRole($role, $key);
        }

        return $instances;
    }

    private function startTemplateRole(string $role, SshKeyPair $key): IncusInstance
    {
        $name = IncusTopologyTemplate::templateName(E2ETopologyKind::Control, $role);

        $start = $this->timer->measure("{$role}.start", fn () => $this->host->startInstance($name));
        if (! $start->successful()) {
            throw new RuntimeException("Could not start {$name}: {$start->errorOutput()}");
        }

        $instance = new IncusInstance($this->host, $name);
        $this->timer->measure("{$role}.agent.ready", fn () => $instance->waitForAgent());

        if ($role === 'control') {
            $this->timer->measure("{$role}.ssh-ready", fn () => $instance->waitForSsh($this->host->config->controlUser, $key));
        }

        return $instance;
    }

    private function launchBlankRole(string $role, SshKeyPair $key): IncusInstance
    {
        $name = IncusTopologyTemplate::templateName(E2ETopologyKind::Control, $role);
        $this->timer->measure("{$role}.launch", fn () => $this->launchBlank($name));
        $instance = new IncusInstance($this->host, $name);
        $this->timer->measure("{$role}.agent.initial", fn () => $instance->waitForAgent());
        $this->timer->measure("{$role}.cloud-init", fn () => $this->host->waitForCloudInit($name));
        $this->timer->measure("{$role}.agent.after-cloud-init", fn () => $instance->waitForAgent());
        $this->timer->measure("{$role}.ssh-authorize", fn () => $instance->authorizeSsh($this->host->config->bootstrapUser, $key));
        $this->timer->measure("{$role}.ssh-ready", fn () => $instance->waitForSsh($this->host->config->bootstrapUser, $key));

        return $instance;
    }

    private function runGatewayNodeNew(
        IncusInstance $control,
        SshKeyPair $key,
        string $host,
    ): void {
        $parts = [
            'cd '.escapeshellarg('/home/'.$this->host->config->controlUser.'/orbit').' && orbit node:new gateway-1',
            '--role=gateway',
            '--host='.escapeshellarg($host),
            '--ssh-user='.escapeshellarg($this->host->config->bootstrapUser),
            '--control-name=control-1',
            '--json',
        ];

        E2ECommand::ssh(
            $control,
            $this->host->config->controlUser,
            $key,
            implode(' ', $parts),
            timeoutSeconds: 900,
        );
    }

    private function useWireGuardGatewayUrl(IncusInstance $control, SshKeyPair $key): void
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
            $control,
            $this->host->config->controlUser,
            $key,
            'cd '.escapeshellarg('/home/'.$this->host->config->controlUser.'/orbit').' && php artisan tinker --execute='.escapeshellarg($php),
            timeoutSeconds: 60,
        );
    }

    private function runAppNodeNew(
        IncusInstance $control,
        SshKeyPair $key,
        string $name,
        string $host,
        string $environment,
        ?string $tld = null,
    ): void {
        $parts = [
            'cd '.escapeshellarg('/home/'.$this->host->config->controlUser.'/orbit').' && orbit node:new',
            escapeshellarg($name),
            '--role=app',
            '--host='.escapeshellarg($host),
            '--environment='.escapeshellarg($environment),
            '--ssh-user='.escapeshellarg($this->host->config->bootstrapUser),
            '--json',
        ];

        if ($tld !== null) {
            $parts[] = '--tld='.escapeshellarg($tld);
        }

        E2ECommand::ssh(
            $control,
            $this->host->config->controlUser,
            $key,
            implode(' ', $parts),
            timeoutSeconds: 900,
        );

        E2EGatewayApi::waitForGatewayApi($control, $this->host->config->controlUser, $key);
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
    private function reestablishWireGuardRoutes(array $instances): void
    {
        $control = $instances['control'] ?? null;
        $gateway = $instances['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $controlIp = $control->waitForIpv4();
        $gatewayIp = $gateway->waitForIpv4();

        E2ENetwork::assignWireGuardIp($control, self::ControlWireGuardIp);
        E2ENetwork::assignWireGuardIp($gateway, self::GatewayWireGuardIp);
        E2ENetwork::routeWireGuardPeer($control, self::GatewayWireGuardIp, $gatewayIp, self::ControlWireGuardIp);
        E2ENetwork::routeWireGuardPeer($gateway, self::ControlWireGuardIp, $controlIp, self::GatewayWireGuardIp);

        $this->routeAppPeer($gateway, $gatewayIp, $instances['dev'] ?? null, self::DevWireGuardIp);
        $this->routeAppPeer($gateway, $gatewayIp, $instances['prod'] ?? null, self::ProdWireGuardIp);
    }

    private function routeAppPeer(IncusInstance $gateway, string $gatewayIp, ?IncusInstance $app, string $appWireGuardIp): void
    {
        if ($app === null) {
            return;
        }

        $appIp = $app->waitForIpv4();

        E2ENetwork::assignWireGuardIp($app, $appWireGuardIp);
        E2ENetwork::routeWireGuardPeer($gateway, $appWireGuardIp, $appIp, self::GatewayWireGuardIp);
        E2ENetwork::routeWireGuardPeer($app, self::GatewayWireGuardIp, $gatewayIp, $appWireGuardIp);
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

    private function launchBlank(string $target): void
    {
        $sourceImageAlias = $this->host->config->blankImage;
        $result = $this->host->launchInstance($sourceImageAlias, $target, timeoutSeconds: $this->host->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not launch {$target} from {$sourceImageAlias}: {$result->errorOutput()}");
        }
    }
}
