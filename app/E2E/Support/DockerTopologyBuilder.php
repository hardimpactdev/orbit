<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class DockerTopologyBuilder
{
    private const string RuntimeImage = 'orbit-e2e-topology-runtime:current';

    public function __construct(
        private E2EConfig $config,
    ) {}

    /**
     * @return list<array{role: string, container: string, image: string}>
     */
    public function build(E2ETopologyKind $kind, string $mode = 'legacy-retarget'): array
    {
        $network = "{$this->config->instancePrefix}-build-{$kind->value}";
        $roles = self::rolesFor($kind);
        $containers = [];
        $composerLockHash = $this->composerLockHash();
        $certSanSet = $this->certSanSetForMode($mode);

        $this->mustRun(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg(self::RuntimeImage)),
            'Docker topology runtime image is missing',
        );
        $this->mustRun(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg(DockerTopologyProvider::runtimeSiblingImage())),
            'Docker Orbit runtime image is missing',
        );

        try {
            $this->mustRun(
                sprintf('docker network create --subnet %s %s', escapeshellarg('10.6.0.0/16'), escapeshellarg($network)),
                "Could not create Docker build network {$network}",
            );

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $containers[$role] = new DockerBuildInstance($container, $network);

                $this->mustRun($this->runCommand($container, $network, $role, $this->containerIpForRole($role), $mode), "Could not start {$container}");
                $this->syncCurrentCheckout($container, $role);
                $this->mustRun($this->runtimeRunCommand($container, $network, $role), "Could not start {$this->runtimeContainerName($container)}");
                $this->prepareRuntimeSource($container, $role);
                $this->persistRuntimeSource($container, $role);
                $this->migrate($containers[$role], $role);
                if ($role === 'gateway') {
                    $this->refreshRuntimeSource($container, $role);
                }
            }

            $this->seedTopology($kind, $containers, $mode);

            $manifest = [];

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $image = self::imageNameFor($kind, $role, $mode);

                if (! self::shouldCommitRole($kind, $role)) {
                    $manifest[] = [
                        'role' => $role,
                        'container' => $container,
                        'image' => $image,
                        'reused' => true,
                    ];

                    continue;
                }

                $this->persistRuntimeSource($container, $role);
                $this->normalizePersistedRuntimeStateOwnership($container, $role);

                $this->mustRun(
                    sprintf(
                        'docker commit --change %s --change %s --change %s --change %s --change %s --change %s %s %s',
                        escapeshellarg('CMD ["/usr/local/bin/orbit-e2e-container"]'),
                        escapeshellarg("LABEL org.orbit.e2e.topology-mode={$mode}"),
                        escapeshellarg("LABEL org.orbit.e2e.kind={$kind->value}"),
                        escapeshellarg("LABEL org.orbit.e2e.role={$role}"),
                        escapeshellarg("LABEL org.orbit.e2e.composer-lock={$composerLockHash}"),
                        escapeshellarg("LABEL org.orbit.e2e.cert-san-set={$certSanSet}"),
                        escapeshellarg($container),
                        escapeshellarg($image),
                    ),
                    "Could not commit {$container}",
                    timeoutSeconds: 600,
                );

                $manifest[] = [
                    'role' => $role,
                    'container' => $container,
                    'image' => $image,
                ];
            }

            return $manifest;
        } finally {
            foreach (array_keys($containers) as $role) {
                $this->run(sprintf(
                    'docker rm -f %s >/dev/null 2>&1 || true',
                    implode(' ', array_map(escapeshellarg(...), $this->managedContainerNames("{$network}-{$role}"))),
                ), timeoutSeconds: 120);
            }

            $this->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 60);
        }
    }

    /**
     * @return list<string>
     */
    public static function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Operator => ['control'],
            E2ETopologyKind::OperatorGateway => ['control', 'gateway'],
            E2ETopologyKind::OperatorGatewayAppdev => ['control', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevIngress => ['control', 'gateway', 'dev', 'ingress'],
            E2ETopologyKind::OperatorGatewayAppdevWebsocket => ['control', 'gateway', 'dev', 'websocket'],
            E2ETopologyKind::OperatorGatewayAppdevS3 => ['control', 'gateway', 'dev', 's3'],
            E2ETopologyKind::OperatorGatewayAppdevIngressWebsocketS3 => ['control', 'gateway', 'dev', 'ingress', 'websocket', 's3'],
            E2ETopologyKind::OperatorGatewayAppdevAppprod => ['control', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['control', 'gateway', 'dev', 'prod', 'agent'],
            E2ETopologyKind::OperatorGatewayAppprodIngress => ['control', 'gateway', 'prod', 'ingress'],
        };
    }

    public static function imageNameFor(E2ETopologyKind $kind, string $role, string $mode = 'legacy-retarget'): string
    {
        $effectiveKind = self::imageKindFor($kind, $role);
        $imageSlug = $effectiveKind->dockerImageSlug();

        if ($mode === 'legacy-retarget') {
            return "orbit-e2e-topology:{$imageSlug}-{$role}-current";
        }

        return "orbit-e2e-topology:{$imageSlug}-{$role}-{$mode}-current";
    }

    private static function imageKindFor(E2ETopologyKind $kind, string $role): E2ETopologyKind
    {
        return $kind;
    }

    private static function shouldCommitRole(E2ETopologyKind $kind, string $role): bool
    {
        return self::ownsImage($kind, $role);
    }

    public static function ownsImage(E2ETopologyKind $kind, string $role): bool
    {
        return self::imageKindFor($kind, $role) === $kind;
    }

    private function runCommand(string $container, string $network, string $role, string $ip, string $mode): string
    {
        $networkAlias = $mode === 'dns-alias'
            ? ' --network-alias '.escapeshellarg($role)
            : '';

        return sprintf(
            'docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name %s --network %s%s --ip %s --volume %s --env %s --env %s %s',
            escapeshellarg($container),
            escapeshellarg($network),
            $networkAlias,
            escapeshellarg($ip),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            escapeshellarg("ORBIT_RUNTIME_CONTAINER={$this->runtimeContainerName($container)}"),
            escapeshellarg(self::RuntimeImage),
        );
    }

    private function runtimeRunCommand(string $nodeContainer, string $network, string $role): string
    {
        $orbitPath = $this->orbitPathForRole($role);
        $gatewayEnv = $role === 'gateway'
            ? ' --env '.escapeshellarg('ORBIT_IS_GATEWAY=1')
            : '';

        return sprintf(
            'docker run -d --restart unless-stopped --name %s --network %s --volume %s --env %s --env %s --env %s%s --workdir %s %s tail -f /dev/null',
            escapeshellarg($this->runtimeContainerName($nodeContainer)),
            escapeshellarg("container:{$nodeContainer}"),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            escapeshellarg("ORBIT_NODE_CONTAINER={$nodeContainer}"),
            escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
            $gatewayEnv,
            escapeshellarg($orbitPath),
            escapeshellarg(DockerTopologyProvider::runtimeSiblingImage()),
        );
    }

    private function prepareRuntimeSource(string $nodeContainer, string $role): void
    {
        $runtimeContainer = $this->runtimeContainerName($nodeContainer);
        $sourcePath = $this->orbitPathForRole($role);

        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($runtimeContainer),
                escapeshellarg('mkdir -p '.escapeshellarg(dirname($sourcePath))),
            ),
            "Could not prepare runtime source parent for {$runtimeContainer}",
            timeoutSeconds: 120,
        );
        $this->mustRun(
            $this->copyPathBetweenContainersCommand($nodeContainer, $runtimeContainer, dirname($sourcePath), basename($sourcePath)),
            "Could not copy {$sourcePath} into {$runtimeContainer}",
            timeoutSeconds: 300,
        );
        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($runtimeContainer),
                escapeshellarg($this->prepareSyncedCheckoutCommand($role, chownSource: false)),
            ),
            "Could not prepare synced checkout in {$runtimeContainer}",
            timeoutSeconds: 120,
        );

        $this->mustRun(
            $this->runtimeDependencyInstallCommand($runtimeContainer, $sourcePath, $role),
            "Could not install runtime dependencies in {$runtimeContainer}",
            timeoutSeconds: 600,
        );
    }

    private function persistRuntimeSource(string $nodeContainer, string $role): void
    {
        $sourcePath = $this->orbitPathForRole($role);

        $this->mustRun(
            $this->copyPathContentsBetweenContainersCommand($this->runtimeContainerName($nodeContainer), $nodeContainer, $sourcePath),
            "Could not persist {$sourcePath} into {$nodeContainer}",
            timeoutSeconds: 300,
        );
        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($nodeContainer),
                escapeshellarg($this->prepareSyncedCheckoutCommand($role)),
            ),
            "Could not prepare persisted checkout in {$nodeContainer}",
            timeoutSeconds: 120,
        );
    }

    private function refreshRuntimeSource(string $nodeContainer, string $role): void
    {
        $sourcePath = $this->orbitPathForRole($role);

        $this->mustRun(
            $this->copyPathContentsBetweenContainersCommand($nodeContainer, $this->runtimeContainerName($nodeContainer), $sourcePath),
            "Could not refresh {$sourcePath} in {$this->runtimeContainerName($nodeContainer)}",
            timeoutSeconds: 300,
        );
    }

    private function normalizePersistedRuntimeStateOwnership(string $nodeContainer, string $role): void
    {
        if ($role !== 'gateway') {
            return;
        }

        $orbitStatePath = "{$this->orbitPathForRole($role)}/storage/app/orbit";

        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($nodeContainer),
                escapeshellarg(sprintf(
                    'if [ -d %s ]; then chown -R orbit:orbit %s; fi',
                    escapeshellarg($orbitStatePath),
                    escapeshellarg($orbitStatePath),
                )),
            ),
            "Could not normalize persisted Orbit runtime state ownership in {$nodeContainer}",
            timeoutSeconds: 60,
        );
    }

    private function syncCurrentCheckout(string $container, string $role): void
    {
        $sourcePath = $this->orbitPathForRole($role);
        $user = $this->userForRole($role);

        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($container),
                escapeshellarg(sprintf(
                    'rm -rf %s && install -d -o %s -g %s %s',
                    escapeshellarg($sourcePath),
                    escapeshellarg($user),
                    escapeshellarg($user),
                    escapeshellarg($sourcePath),
                )),
            ),
            "Could not prepare checkout target in {$container}",
            timeoutSeconds: 120,
        );

        $this->mustRun(
            $this->copyCurrentCheckoutCommand($container, $sourcePath),
            "Could not sync current checkout into {$container}",
            timeoutSeconds: 300,
        );

        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($container),
                escapeshellarg($this->prepareSyncedCheckoutCommand($role)),
            ),
            "Could not prepare synced checkout in {$container}",
            timeoutSeconds: 120,
        );
    }

    private function copyCurrentCheckoutCommand(string $container, string $targetPath): string
    {
        return sprintf(
            'COPYFILE_DISABLE=1 tar %s -czf - -C %s . | docker exec -i %s tar --warning=no-unknown-keyword -xzf - -C %s',
            $this->archiveExcludeArguments(),
            escapeshellarg(base_path()),
            escapeshellarg($container),
            escapeshellarg($targetPath),
        );
    }

    private function archiveExcludeArguments(): string
    {
        return collect(E2ECurrentCheckout::archiveExcludePatterns())
            ->map(fn (string $pattern): string => '--exclude='.escapeshellarg($pattern))
            ->implode(' ');
    }

    private function prepareSyncedCheckoutCommand(string $role, bool $chownSource = true): string
    {
        $sourcePath = $this->orbitPathForRole($role);
        $user = $this->userForRole($role);

        $commands = [
            $this->gatewayArtifactCompatibilityCommand($role),
            $this->gatewayEnvironmentCommand($role),
            $chownSource ? sprintf('chown -R %s:%s %s', escapeshellarg($user), escapeshellarg($user), escapeshellarg($sourcePath)) : '',
            sprintf('chmod 0755 %s', escapeshellarg("{$sourcePath}/bin/orbit")),
            sprintf('ln -sfn %s %s', escapeshellarg("{$sourcePath}/bin/orbit"), escapeshellarg('/usr/local/bin/orbit')),
        ];

        return implode(' && ', array_filter($commands));
    }

    private function gatewayArtifactCompatibilityCommand(string $role): string
    {
        if ($role !== 'gateway') {
            return '';
        }

        $sourcePath = $this->orbitPathForRole($role);
        $gatewayDirectory = escapeshellarg("{$sourcePath}/apps/gateway");
        $gatewayArtisan = escapeshellarg("{$sourcePath}/apps/gateway/artisan");
        $rootArtisan = escapeshellarg("{$sourcePath}/artisan");

        return <<<SH
if [ ! -f {$gatewayArtisan} ] && [ -f {$rootArtisan} ]; then
    mkdir -p {$gatewayDirectory}
    cat > {$gatewayArtisan} <<'PHP'
#!/usr/bin/env php
<?php

require __DIR__.'/../../artisan';
PHP
    chmod 0755 {$gatewayArtisan}
fi
SH;
    }

    private function gatewayEnvironmentCommand(string $role): string
    {
        if ($role !== 'gateway') {
            return '';
        }

        $sourcePath = escapeshellarg($this->orbitPathForRole($role));

        return <<<SH
cd {$sourcePath}
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
    else
        touch .env
    fi
fi
grep -Ev '^ORBIT_IS_GATEWAY=' .env > .env.tmp || true
mv .env.tmp .env
printf '%s\\n' 'ORBIT_IS_GATEWAY=true' >> .env
SH;
    }

    private function runtimeDependencyInstallCommand(string $runtimeContainer, string $sourcePath, string $role): string
    {
        return sprintf(
            'docker exec --env %s --workdir %s %s sh -lc %s',
            escapeshellarg("ORBIT_SOURCE_PATH={$sourcePath}"),
            escapeshellarg($sourcePath),
            escapeshellarg($runtimeContainer),
            escapeshellarg($this->composerInstallCommandForRole($sourcePath, $role)),
        );
    }

    private function composerInstallCommandForRole(string $sourcePath, string $role): string
    {
        $composerInstall = 'composer install --no-interaction --prefer-dist --optimize-autoloader';

        if ($role !== 'gateway') {
            return sprintf(
                'cd %s && %s',
                escapeshellarg("{$sourcePath}/apps/cli"),
                $composerInstall,
            );
        }

        return sprintf(
            'if [ -f %s ]; then cd %s && %s; else cd %s && %s; fi',
            escapeshellarg("{$sourcePath}/apps/gateway/composer.json"),
            escapeshellarg("{$sourcePath}/apps/gateway"),
            $composerInstall,
            escapeshellarg($sourcePath),
            $composerInstall,
        );
    }

    private function copyPathBetweenContainersCommand(string $sourceContainer, string $targetContainer, string $parentPath, string $pathName): string
    {
        return sprintf(
            'docker exec %s tar -C %s -cf - %s | docker exec -i %s tar -C %s -xf -',
            escapeshellarg($sourceContainer),
            escapeshellarg($parentPath),
            escapeshellarg($pathName),
            escapeshellarg($targetContainer),
            escapeshellarg($parentPath),
        );
    }

    private function copyPathContentsBetweenContainersCommand(string $sourceContainer, string $targetContainer, string $path): string
    {
        return sprintf(
            'docker exec %s tar -C %s -cf - . | docker exec -i %s tar -C %s -xf -',
            escapeshellarg($sourceContainer),
            escapeshellarg($path),
            escapeshellarg($targetContainer),
            escapeshellarg($path),
        );
    }

    private function migrate(DockerBuildInstance $instance, string $role): void
    {
        if ($role !== 'gateway') {
            return;
        }

        $user = $this->userForRole($role);
        $path = $this->orbitPathForRole($role);

        E2ECommand::ssh($instance, $user, new SshKeyPair('/dev/null', '/dev/null'), "cd {$path} && orbit migrate --force", timeoutSeconds: 120);
    }

    private function orbitPathForRole(string $role): string
    {
        return $role === 'control' ? '/home/control/orbit' : '/home/orbit/orbit';
    }

    private function userForRole(string $role): string
    {
        return $role === 'control' ? 'control' : 'orbit';
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedTopology(E2ETopologyKind $kind, array $containers, string $mode): void
    {
        $control = $containers['control'] ?? null;
        $gateway = $containers['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        $key = new SshKeyPair('/dev/null', '/dev/null');

        E2ECommand::ssh($gateway, 'orbit', $key, 'cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway 10.6.0.2 --skip-runtime-install --skip-wireguard-install', timeoutSeconds: 120);
        $this->refreshRuntimeSource($gateway->name(), 'gateway');
        $this->startGatewayScheduler($gateway);
        $this->waitForGatewayScheduleDoctor($gateway, $key);
        $this->refreshRuntimeSource($gateway->name(), 'gateway');
        E2EGatewayApi::seedControlIdentity($gateway, '10.6.0.3', 'control');
        $this->refreshRuntimeSource($gateway->name(), 'gateway');
        if ($mode === 'dns-alias') {
            E2EGatewayApi::start(
                $gateway,
                "docker-build-{$kind->value}",
                wireguardIdentity: '10.6.0.2',
                bindAddress: '0.0.0.0',
                certKey: 'gateway',
                certSans: ['10.6.0.2'],
            );
        } else {
            E2EGatewayApi::start($gateway, "docker-build-{$kind->value}");
        }
        $this->persistRuntimeSource($gateway->name(), 'gateway');

        E2EGatewayApi::waitForGatewayApi($control, 'control', $key);
        $this->configureClientCliGateways($containers, $mode);

        $this->seedRemoteShellSshAccess($gateway, $containers);
        $this->seedRemoteShellAgentSshAccess($gateway, $containers);
        $this->waitForManagedSshHostKeys($gateway, $containers, $mode);

        if (isset($containers['dev'])) {
            $host = $mode === 'dns-alias' ? 'dev' : '10.6.0.4';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';
            $hostKeyHost = $this->hostKeyHostOption('dev', $mode);

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && orbit orbit:internal:bake-app-node app-dev-1 --role=app-dev --host={$host}{$hostKeyHost} --wireguard-address=10.6.0.4 --tld=test --gateway-endpoint={$gatewayEndpoint} --user=orbit", timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
            $this->seedAppdevDatabaseAndRedis($gateway, $key);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }

        if (isset($containers['ingress'])) {
            $host = $mode === 'dns-alias' ? 'ingress' : '10.6.0.7';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';
            $hostKeyHost = $this->hostKeyHostOption('ingress', $mode);

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && orbit orbit:internal:bake-ingress-node edge-1 --host={$host}{$hostKeyHost} --wireguard-address=10.6.0.7 --gateway-endpoint={$gatewayEndpoint} --user=orbit", timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }

        if (isset($containers['prod'])) {
            $host = $mode === 'dns-alias' ? 'prod' : '10.6.0.5';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';
            $hostKeyHost = $this->hostKeyHostOption('prod', $mode);
            $ingress = isset($containers['ingress']) ? ' --ingress-node=edge-1' : '';

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && orbit orbit:internal:bake-app-node app-prod-1 --role=app-prod --host={$host}{$hostKeyHost} --wireguard-address=10.6.0.5 --gateway-endpoint={$gatewayEndpoint} --user=orbit{$ingress}", timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }

        if (isset($containers['agent'])) {
            $host = $mode === 'dns-alias' ? 'agent' : '10.6.0.6';
            $gatewayEndpoint = $mode === 'dns-alias' ? 'gateway' : '10.6.0.2';
            $hostKeyHost = $this->hostKeyHostOption('agent', $mode);

            E2ECommand::ssh($gateway, 'orbit', $key, "cd /home/orbit/orbit && orbit orbit:internal:bake-agent-node agent-1 --host={$host}{$hostKeyHost} --wireguard-address=10.6.0.6 --tld=agent --gateway-endpoint={$gatewayEndpoint} --user=orbit", timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function configureClientCliGateways(array $containers, string $mode): void
    {
        foreach ($containers as $role => $container) {
            if ($role === 'gateway') {
                continue;
            }

            $this->mustRun(
                sprintf(
                    'docker exec %s sh -lc %s',
                    escapeshellarg($container->name()),
                    escapeshellarg($this->configureClientCliGatewayCommand($role, $mode)),
                ),
                "Could not configure {$role} CLI gateway endpoint",
                timeoutSeconds: 60,
            );
            $this->refreshRuntimeSource($container->name(), $role);
        }
    }

    private function configureClientCliGatewayCommand(string $role, string $mode): string
    {
        $sourcePath = $this->orbitPathForRole($role);
        $user = $this->userForRole($role);
        $gatewayUrl = $mode === 'dns-alias' ? 'http://gateway' : 'http://10.6.0.2';

        return implode(' && ', [
            sprintf('cd %s', escapeshellarg("{$sourcePath}/apps/cli")),
            'touch .env',
            "grep -Ev '^(ORBIT_GATEWAY_URL|ORBIT_GATEWAY_IDENTITY)=' .env > .env.tmp || true",
            'mv .env.tmp .env',
            sprintf("printf 'ORBIT_GATEWAY_URL=%%s\\n' %s >> .env", escapeshellarg($gatewayUrl)),
            sprintf('chown %s:%s %s', escapeshellarg($user), escapeshellarg($user), escapeshellarg("{$sourcePath}/apps/cli/.env")),
        ]);
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedRemoteShellSshAccess(DockerBuildInstance $gateway, array $containers): void
    {
        if (! $this->hasManagedSshRole($containers)) {
            return;
        }

        $publicKey = $this->gatewayPublicKey($gateway);

        foreach ($this->managedSshRoles() as $role) {
            if (! isset($containers[$role])) {
                continue;
            }

            $this->authorizeGatewaySshKey($containers[$role], $publicKey);
        }
    }

    private function startGatewayScheduler(DockerBuildInstance $gateway): void
    {
        $this->mustRun(
            sprintf(
                'docker exec --detach --workdir %s %s orbit orbit-scheduler',
                escapeshellarg('/home/orbit/orbit'),
                escapeshellarg($this->runtimeContainerName($gateway->name())),
            ),
            'Could not start Docker build gateway scheduler',
            timeoutSeconds: 60,
        );
    }

    private function waitForGatewayScheduleDoctor(DockerBuildInstance $gateway, SshKeyPair $key): void
    {
        $deadline = time() + 30;
        $last = null;

        do {
            $this->persistRuntimeSource($gateway->name(), 'gateway');

            $last = E2ECommand::ssh(
                $gateway,
                'orbit',
                $key,
                'cd /home/orbit/orbit && orbit doctor --node=gateway --family=schedule --restore --json',
                timeoutSeconds: 120,
                allowFailure: true,
            );

            if ($last->successful()) {
                return;
            }

            sleep(1);
        } while (time() < $deadline);

        throw new RuntimeException(trim("SSH command failed: cd /home/orbit/orbit && orbit doctor --node=gateway --family=schedule --restore --json\n".$last?->output().$last?->errorOutput()));
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedRemoteShellAgentSshAccess(DockerBuildInstance $gateway, array $containers): void
    {
        if (! isset($containers['agent'])) {
            return;
        }

        $publicKey = $this->gatewayPublicKey($gateway);

        $this->authorizeGatewaySshKey($containers['agent'], $publicKey);
    }

    private function gatewayPublicKey(DockerBuildInstance $gateway): string
    {
        $result = E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            'install -d -m 700 ~/.ssh && if ! test -f ~/.ssh/id_ed25519; then ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C orbit-e2e-gateway >/dev/null; fi && cat ~/.ssh/id_ed25519.pub',
            timeoutSeconds: 60,
        );

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new RuntimeException('Could not create Docker gateway SSH key for RemoteShell E2E access.');
        }

        return $publicKey;
    }

    private function authorizeGatewaySshKey(DockerBuildInstance $instance, string $publicKey): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'install -d -m 700 -o orbit -g orbit /home/orbit/.ssh && touch /home/orbit/.ssh/authorized_keys && chown orbit:orbit /home/orbit/.ssh/authorized_keys && chmod 600 /home/orbit/.ssh/authorized_keys && grep -qxF %1$s /home/orbit/.ssh/authorized_keys || printf "%%s\n" %1$s >> /home/orbit/.ssh/authorized_keys',
                escapeshellarg($publicKey),
            ),
            "Could not authorize gateway SSH key in {$instance->name()}",
            timeoutSeconds: 60,
        );
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function waitForManagedSshHostKeys(DockerBuildInstance $gateway, array $containers, string $mode): void
    {
        $targets = [];

        foreach ([...$this->managedSshRoles(), 'agent'] as $role) {
            if (! isset($containers[$role])) {
                continue;
            }

            $targets[] = $this->containerIpForRole($role);
        }

        if ($targets === []) {
            return;
        }

        $targetList = implode(' ', array_map(escapeshellarg(...), $targets));
        $command = <<<SH
for target in {$targetList}; do
  attempt=1
  while ! ssh-keyscan -T 2 -t ed25519,ecdsa,rsa "\$target" >/dev/null 2>&1; do
    if [ "\$attempt" -ge 30 ]; then
      ssh-keyscan -T 10 -t ed25519,ecdsa,rsa "\$target"
      exit 1
    fi

    attempt=\$((attempt + 1))
    sleep 1
  done
done
SH;

        E2ECommand::ssh(
            $gateway,
            'orbit',
            new SshKeyPair('/dev/null', '/dev/null'),
            $command,
            timeoutSeconds: 120,
        );
    }

    private function ipForRole(string $role): string
    {
        return match ($role) {
            'gateway' => '10.6.0.2',
            'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            'agent' => '10.6.0.6',
            'ingress' => '10.6.0.7',
            'websocket' => '10.6.0.9',
            's3' => '10.6.0.10',
            default => throw new RuntimeException("Unknown Docker topology role {$role}."),
        };
    }

    private function hostKeyHostOption(string $role, string $mode): string
    {
        return $mode === 'dns-alias'
            ? ' --host-key-host='.escapeshellarg($this->containerIpForRole($role))
            : '';
    }

    private function containerIpForRole(string $role): string
    {
        if ($role === 'ingress') {
            return '10.6.0.8';
        }

        return $this->ipForRole($role);
    }

    private function seedAppdevDatabaseAndRedis(DockerBuildInstance $gateway, SshKeyPair $key): void
    {
        E2ECommand::ssh(
            $gateway,
            'orbit',
            $key,
            'cd /home/orbit/orbit && orbit tinker --execute='.escapeshellarg(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp()),
            timeoutSeconds: 120,
        );
    }

    private function composerLockHash(): string
    {
        $path = base_path('composer.lock');

        return is_file($path) ? hash_file('sha256', $path) : 'missing';
    }

    private function certSanSetForMode(string $mode): string
    {
        return $mode === 'dns-alias'
            ? 'DNS:gateway,IP:10.6.0.2'
            : 'IP:10.6.0.2';
    }

    /**
     * @return list<string>
     */
    private function managedContainerNames(string $nodeContainer): array
    {
        return [
            $nodeContainer,
            $this->runtimeContainerName($nodeContainer),
            "{$nodeContainer}-orbit-caddy",
        ];
    }

    private function runtimeContainerName(string $nodeContainer): string
    {
        return "{$nodeContainer}-orbit-runtime";
    }

    /**
     * @return list<string>
     */
    private function managedSshRoles(): array
    {
        return ['dev', 'prod', 'ingress', 'websocket', 's3'];
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function hasManagedSshRole(array $containers): bool
    {
        foreach ($this->managedSshRoles() as $role) {
            if (isset($containers[$role])) {
                return true;
            }
        }

        return false;
    }

    private function mustRun(string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $this->run($command, $timeoutSeconds);

        if ($result->successful()) {
            return $result;
        }

        throw new RuntimeException("{$message}: {$result->output()}{$result->errorOutput()}");
    }

    private function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        if ($timeoutSeconds !== null) {
            return Process::timeout($timeoutSeconds)->run($command);
        }

        return Process::run($command);
    }
}

final readonly class DockerBuildInstance implements E2EInstance
{
    public function __construct(
        private string $name,
        private ?string $network = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->run(sprintf(
            'docker exec %s sh -lc %s',
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->run(sprintf(
            'docker exec --user %s %s sh -lc %s',
            escapeshellarg($user),
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

    public function copyFileToInstance(string $sourcePath, string $targetPath): void
    {
        $this->run(sprintf(
            'docker cp %s %s:%s',
            escapeshellarg($sourcePath),
            escapeshellarg($this->name),
            escapeshellarg($targetPath),
        ));
    }

    public function waitForAgent(): void {}

    public function waitForIpv4(): string
    {
        if ($this->network === null) {
            throw new RuntimeException("Docker network is unknown for {$this->name}.");
        }

        $result = $this->run(sprintf(
            'docker inspect -f %s %s',
            escapeshellarg('{{(index .NetworkSettings.Networks "'.$this->network.'").IPAddress}}'),
            escapeshellarg($this->name),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Could not inspect Docker container {$this->name}: {$result->errorOutput()}");
        }

        return trim($result->output());
    }

    public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

    public function delete(): void
    {
        $this->run(sprintf(
            'docker rm -f %s >/dev/null 2>&1 || true',
            implode(' ', array_map(escapeshellarg(...), [
                $this->name,
                "{$this->name}-orbit-runtime",
                "{$this->name}-orbit-caddy",
            ])),
        ), timeoutSeconds: 60);
    }

    private function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        if ($timeoutSeconds !== null) {
            return Process::timeout($timeoutSeconds)->run($command);
        }

        return Process::run($command);
    }
}
