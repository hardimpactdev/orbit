<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class DockerTopologyBuilder
{
    private const string ComposerCachePath = '/tmp/orbit-composer-cache';

    private const string ComposerHomePath = '/tmp/orbit-composer-home';

    private const string ComposerHelperImage = 'composer:2';

    public function __construct(
        private E2EConfig $config,
    ) {}

    /**
     * @return list<array{role: string, container: string, image: string}>
     */
    public function build(E2ETopologyKind $kind, string $mode = 'dns-alias'): array
    {
        $network = E2ETopologyArtifactNamespace::dockerBuildName($this->config->instancePrefix, $kind);
        $roles = self::rolesFor($kind);
        $containers = [];
        $runtimeDependencySources = [];
        $composerLockHash = $this->composerLockHash();
        $composerCacheVolume = $this->composerCacheVolume($composerLockHash);

        $this->mustRun(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg(self::runtimeImage())),
            'Docker topology runtime image is missing',
        );
        if (array_any($roles, self::roleUsesRuntimeSibling(...))) {
            $this->mustRun(
                sprintf('docker image inspect %s >/dev/null', escapeshellarg(DockerTopologyProvider::runtimeSiblingImage())),
                'Docker Orbit runtime image is missing',
            );
        }
        if (array_any($roles, fn (string $role): bool => ! self::roleUsesRuntimeSibling($role))) {
            $this->mustRun(
                sprintf('docker image inspect %s >/dev/null', escapeshellarg(self::composerHelperImage())),
                'Docker Composer helper image is missing',
            );
        }

        try {
            $networkPlan = $this->createNetwork($network);
            $certSanSet = $this->certSanSetForMode($mode, $networkPlan);

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $containers[$role] = new DockerBuildInstance($container, $network);

                $this->mustRun($this->runCommand($container, $network, $role, $this->containerIpForRole($role, $networkPlan), $mode), "Could not start {$container}");
                $this->syncCurrentCheckout($container, $role);
                if (self::roleUsesRuntimeSibling($role)) {
                    $this->mustRun($this->runtimeRunCommand($container, $network, $role, $composerCacheVolume), "Could not start {$this->runtimeContainerName($container)}");
                } else {
                    $this->mustRun($this->composerHelperRunCommand($container, $role, $composerCacheVolume), "Could not start {$this->composerHelperContainerName($container)}");
                }

                $dependencyKey = $this->runtimeDependencyKeyForRole($role);
                $dependencySource = $runtimeDependencySources[$dependencyKey] ?? null;

                $this->prepareRoleSource($container, $role, $dependencySource);

                if ($dependencySource === null) {
                    $runtimeDependencySources[$dependencyKey] = $this->dependencyContainerName($container, $role);
                }

                $this->persistRoleSource($container, $role);
                $this->migrate($containers[$role], $role);
                if ($role === 'gateway') {
                    $this->refreshRuntimeSource($container, $role);
                }
            }

            $this->seedTopology($kind, $containers, $mode, $networkPlan);

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

                $this->persistRoleSource($container, $role);
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
                    implode(' ', array_map(escapeshellarg(...), $this->managedContainerNames("{$network}-{$role}", $role))),
                ), timeoutSeconds: 120);
            }

            $this->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 60);
        }
    }

    private function createNetwork(string $network): DockerTopologyNetworkPlan
    {
        $token = getenv('TEST_TOKEN');
        $maxAttempts = is_string($token) && $token !== '' ? 16 : 224;
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $networkPlan = DockerTopologyNetworkPlan::fromEnvironment($network, $attempt);
            $result = $this->run(
                sprintf('docker network create --subnet %s %s', escapeshellarg($networkPlan->subnet()), escapeshellarg($network)),
                timeoutSeconds: 60,
            );

            if ($result->successful()) {
                return $networkPlan;
            }

            $lastError = trim($result->errorOutput().' '.$result->output());

            if (! str_contains($lastError, 'Pool overlaps')) {
                throw new RuntimeException("Could not create Docker build network {$network}: {$lastError}");
            }
        }

        throw new RuntimeException("Could not create Docker build network {$network}: {$lastError}");
    }

    /**
     * @return list<string>
     */
    public static function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Operator => ['operator'],
            E2ETopologyKind::OperatorGateway => ['operator', 'gateway'],
            E2ETopologyKind::OperatorGatewayAppdev => ['operator', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprod => ['operator', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAgent => ['operator', 'gateway', 'agent'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['operator', 'gateway', 'dev', 'prod', 'agent'],
            E2ETopologyKind::OperatorGatewayAppprodIngress => ['operator', 'gateway', 'prod', 'ingress'],
        };
    }

    public static function imageNameFor(E2ETopologyKind $kind, string $role, string $mode = 'dns-alias'): string
    {
        $role = self::canonicalRole($role);

        return self::imageNameForCanonicalRole($kind, $role, $mode);
    }

    private static function imageNameForCanonicalRole(E2ETopologyKind $kind, string $role, string $mode): string
    {
        $effectiveKind = self::imageKindFor($kind, $role);
        $imageSlug = E2ETopologyArtifactNamespace::dockerImageSlug($effectiveKind->dockerImageSlug());

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

    private static function roleUsesRuntimeSibling(string $role): bool
    {
        return $role === 'gateway';
    }

    public static function ownsImage(E2ETopologyKind $kind, string $role): bool
    {
        $role = self::canonicalRole($role);

        return self::imageKindFor($kind, $role) === $kind;
    }

    private static function canonicalRole(string $role): string
    {
        return $role === 'control' ? 'operator' : $role;
    }

    private function runCommand(string $container, string $network, string $role, string $ip, string $mode): string
    {
        $networkAlias = $mode === 'dns-alias'
            ? ' --network-alias '.escapeshellarg($role)
            : '';
        $runtimeContainerEnv = self::roleUsesRuntimeSibling($role)
            ? ' --env '.escapeshellarg("ORBIT_RUNTIME_CONTAINER={$this->runtimeContainerName($container)}")
            : '';

        return sprintf(
            'docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s --name %s --network %s%s --ip %s --volume %s --env %s%s %s',
            $this->dockerSocketGroupAddOption(),
            escapeshellarg($container),
            escapeshellarg($network),
            $networkAlias,
            escapeshellarg($ip),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            $runtimeContainerEnv,
            escapeshellarg(self::runtimeImage()),
        );
    }

    private function dockerSocketGroupAddOption(): string
    {
        return '--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"';
    }

    public static function runtimeImage(): string
    {
        return E2ETopologyArtifactNamespace::dockerRuntimeImage('orbit-e2e-topology-runtime');
    }

    public static function composerHelperImage(): string
    {
        return self::ComposerHelperImage;
    }

    private function runtimeRunCommand(string $nodeContainer, string $network, string $role, string $composerCacheVolume): string
    {
        $orbitPath = $this->orbitPathForRole($role);
        $gatewayEnv = $role === 'gateway'
            ? ' --env '.escapeshellarg('ORBIT_IS_GATEWAY=1')
            : '';

        return sprintf(
            'docker run -d --restart unless-stopped --name %s --network %s --volume %s --mount %s --env %s --env %s --env %s%s --workdir %s %s tail -f /dev/null',
            escapeshellarg($this->runtimeContainerName($nodeContainer)),
            escapeshellarg("container:{$nodeContainer}"),
            escapeshellarg('/var/run/docker.sock:/var/run/docker.sock'),
            escapeshellarg($this->composerCacheMount($composerCacheVolume)),
            escapeshellarg("ORBIT_E2E_DOCKER_NETWORK={$network}"),
            escapeshellarg("ORBIT_NODE_CONTAINER={$nodeContainer}"),
            escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
            $gatewayEnv,
            escapeshellarg($orbitPath),
            escapeshellarg(DockerTopologyProvider::runtimeSiblingImage()),
        );
    }

    private function composerHelperRunCommand(string $nodeContainer, string $role, string $composerCacheVolume): string
    {
        $orbitPath = $this->orbitPathForRole($role);

        return sprintf(
            'docker run -d --name %s --network %s --mount %s --env %s --workdir %s %s tail -f /dev/null',
            escapeshellarg($this->composerHelperContainerName($nodeContainer)),
            escapeshellarg("container:{$nodeContainer}"),
            escapeshellarg($this->composerCacheMount($composerCacheVolume)),
            escapeshellarg("ORBIT_SOURCE_PATH={$orbitPath}"),
            escapeshellarg($orbitPath),
            escapeshellarg(self::composerHelperImage()),
        );
    }

    private function composerCacheMount(string $composerCacheVolume): string
    {
        $hostCache = getenv('ORBIT_E2E_DOCKER_COMPOSER_CACHE');

        if (is_string($hostCache) && trim($hostCache) !== '') {
            return 'type=bind,src='.trim($hostCache).',dst='.self::ComposerCachePath;
        }

        return "type=volume,src={$composerCacheVolume},dst=".self::ComposerCachePath;
    }

    private function composerCacheVolume(string $composerLockHash): string
    {
        return "{$this->config->instancePrefix}-composer-cache-".substr($composerLockHash, 0, 16);
    }

    private function composerCacheReadOnly(): bool
    {
        $value = getenv('ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY');

        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    private function prepareDependencySource(string $nodeContainer, string $role, ?string $dependencySourceContainer = null): void
    {
        $dependencyContainer = $this->dependencyContainerName($nodeContainer, $role);
        $sourcePath = $this->orbitPathForRole($role);

        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($dependencyContainer),
                escapeshellarg('mkdir -p '.escapeshellarg(dirname($sourcePath))),
            ),
            "Could not prepare dependency source parent for {$dependencyContainer}",
            timeoutSeconds: 120,
        );
        $this->mustRun(
            $this->copyPathBetweenContainersCommand($nodeContainer, $dependencyContainer, dirname($sourcePath), basename($sourcePath)),
            "Could not copy {$sourcePath} into {$dependencyContainer}",
            timeoutSeconds: 300,
        );
        $this->mustRun(
            sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($dependencyContainer),
                escapeshellarg($this->prepareSyncedCheckoutCommand($role, chownSource: false)),
            ),
            "Could not prepare synced checkout in {$dependencyContainer}",
            timeoutSeconds: 120,
        );

        if ($dependencySourceContainer !== null) {
            $this->mustRun(
                $this->runtimeDependencyReuseCommand($dependencySourceContainer, $dependencyContainer, $role),
                "Could not reuse dependencies in {$dependencyContainer}",
                timeoutSeconds: 300,
            );

            return;
        }

        $this->mustRun(
            $this->runtimeDependencyInstallCommand($dependencyContainer, $sourcePath, $role),
            "Could not install dependencies in {$dependencyContainer}",
            timeoutSeconds: max(1200, $this->config->timeoutSeconds),
        );
    }

    private function prepareRoleSource(string $nodeContainer, string $role, ?string $dependencySourceContainer = null): void
    {
        $this->prepareDependencySource($nodeContainer, $role, $dependencySourceContainer);
    }

    private function persistRuntimeSource(string $nodeContainer, string $role): void
    {
        $sourcePath = $this->orbitPathForRole($role);

        $this->mustRun(
            $this->copyPathContentsBetweenContainersCommand($this->dependencyContainerName($nodeContainer, $role), $nodeContainer, $sourcePath),
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

    private function persistRoleSource(string $nodeContainer, string $role): void
    {
        $this->persistRuntimeSource($nodeContainer, $role);
    }

    private function dependencyContainerName(string $nodeContainer, string $role): string
    {
        return self::roleUsesRuntimeSibling($role)
            ? $this->runtimeContainerName($nodeContainer)
            : $this->composerHelperContainerName($nodeContainer);
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

        $archive = E2ECurrentCheckout::buildArchive();

        try {
            $this->mustRun(
                $this->copyCurrentCheckoutCommand($container, $sourcePath, $archive),
                "Could not sync current checkout into {$container}",
                timeoutSeconds: 300,
            );
        } finally {
            @unlink($archive);
        }

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

    private function copyCurrentCheckoutCommand(string $container, string $targetPath, string $archive): string
    {
        return sprintf(
            'docker exec -i %s tar --warning=no-unknown-keyword -xzf - -C %s < %s',
            escapeshellarg($container),
            escapeshellarg($targetPath),
            escapeshellarg($archive),
        );
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
        $environment = [
            "ORBIT_SOURCE_PATH={$sourcePath}",
            'COMPOSER_CACHE_DIR='.self::ComposerCachePath,
            'COMPOSER_HOME='.self::ComposerHomePath,
            'COMPOSER_PROCESS_TIMEOUT=1200',
            'COMPOSER_ALLOW_SUPERUSER=1',
        ];

        $environmentFlags = implode(' ', array_map(
            fn (string $value): string => '--env '.escapeshellarg($value),
            $environment,
        ));

        return sprintf(
            'docker exec %s --workdir %s %s sh -lc %s',
            $environmentFlags,
            escapeshellarg($sourcePath),
            escapeshellarg($runtimeContainer),
            escapeshellarg($this->composerInstallCommandForRole($sourcePath, $role)),
        );
    }

    private function runtimeDependencyReuseCommand(string $sourceRuntimeContainer, string $targetRuntimeContainer, string $role): string
    {
        $sourcePath = $this->orbitPathForRole($role);

        return sprintf(
            'docker exec %s tar -C %s -cf - vendor apps/cli/vendor | docker exec -i %s sh -lc %s',
            escapeshellarg($sourceRuntimeContainer),
            escapeshellarg($sourcePath),
            escapeshellarg($targetRuntimeContainer),
            escapeshellarg(sprintf(
                'cd %s && rm -rf vendor apps/cli/vendor && tar -xf -',
                escapeshellarg($sourcePath),
            )),
        );
    }

    private function composerInstallCommandForRole(string $sourcePath, string $role): string
    {
        $composerInstall = $this->composerConfigCommand().' && git config --global --add safe.directory '.escapeshellarg('*').' >/dev/null && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress';
        $commands = [
            sprintf('cd %s && %s', escapeshellarg($sourcePath), $composerInstall),
        ];

        if (is_file(base_path('apps/cli/composer.json'))) {
            $commands[] = sprintf('cd %s && %s', escapeshellarg("{$sourcePath}/apps/cli"), $composerInstall);
        }

        if ($role === 'gateway' && $this->hasRelocatedGatewayApp()) {
            $commands[] = sprintf(
                'if [ -f %s ]; then cd %s && %s; fi',
                escapeshellarg("{$sourcePath}/apps/gateway/composer.json"),
                escapeshellarg("{$sourcePath}/apps/gateway"),
                $composerInstall,
            );
        }

        return implode(' && ', $commands);
    }

    private function composerConfigCommand(): string
    {
        $config = [
            'config' => [
                'cache-dir' => self::ComposerCachePath,
                'github-protocols' => ['https'],
            ],
        ];

        if ($this->composerCacheReadOnly()) {
            $config['config']['cache-read-only'] = true;
        }

        return sprintf(
            'mkdir -p %s && printf %%s %s > %s',
            escapeshellarg(self::ComposerHomePath),
            escapeshellarg(json_encode($config, JSON_THROW_ON_ERROR)),
            escapeshellarg(self::ComposerHomePath.'/config.json'),
        );
    }

    private function runtimeDependencyKeyForRole(string $role): string
    {
        if ($role === 'gateway' && $this->hasRelocatedGatewayApp()) {
            return 'gateway';
        }

        return $this->runtimeDependencyPathForRole($role);
    }

    private function runtimeDependencyPathForRole(string $role): string
    {
        $sourcePath = $this->orbitPathForRole($role);

        if ($role === 'gateway' && $this->hasRelocatedGatewayApp()) {
            return "{$sourcePath}/apps/gateway";
        }

        return "{$sourcePath}/apps/cli";
    }

    private function hasRelocatedGatewayApp(): bool
    {
        return is_file(base_path('apps/gateway/composer.json'))
            && is_file(base_path('apps/gateway/artisan'));
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
        $user = $this->userForRole($role);
        $path = $this->orbitPathForRole($role);

        E2ECommand::ssh($instance, $user, new SshKeyPair('/dev/null', '/dev/null'), "cd {$path} && orbit migrate --force", timeoutSeconds: 120);
    }

    private function orbitPathForRole(string $role): string
    {
        return '/home/orbit/orbit';
    }

    private function userForRole(string $role): string
    {
        return 'orbit';
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function seedTopology(E2ETopologyKind $kind, array $containers, string $mode, DockerTopologyNetworkPlan $networkPlan): void
    {
        $operator = $containers['operator'] ?? $containers['control'] ?? null;
        $gateway = $containers['gateway'] ?? null;

        if ($operator === null || $gateway === null) {
            return;
        }

        $key = new SshKeyPair('/dev/null', '/dev/null');
        $gatewayWireGuardAddress = $this->wireGuardAddressForRole('gateway', $networkPlan, $mode);
        $operatorHost = $this->hostForRole('operator', $networkPlan, $mode);
        $operatorWireGuardAddress = $this->wireGuardAddressForRole('operator', $networkPlan, $mode);
        $gatewayEndpoint = $this->gatewayEndpoint($networkPlan, $mode);
        $gatewayReachabilityHost = $mode === 'dns-alias'
            ? 'gateway'
            : $networkPlan->ipForRole('gateway');

        E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
            'cd /home/orbit/orbit && orbit orbit:internal:bootstrap-gateway-local gateway %s --skip-runtime-install --skip-wireguard-install',
            $gatewayWireGuardAddress,
        ), timeoutSeconds: 120);
        $this->refreshRuntimeSource($gateway->name(), 'gateway');
        $this->startGatewayScheduler($gateway);
        $this->waitForGatewayScheduleDoctor($gateway, $key);
        $this->refreshRuntimeSource($gateway->name(), 'gateway');
        E2EGatewayApi::seedOperatorIdentity($gateway, $operatorHost, 'orbit', $gatewayEndpoint, $operatorWireGuardAddress);
        $this->refreshRuntimeSource($gateway->name(), 'gateway');
        if ($mode === 'dns-alias') {
            E2EGatewayApi::start(
                $gateway,
                "docker-build-{$kind->value}",
                wireguardIdentity: $gatewayWireGuardAddress,
                bindAddress: '0.0.0.0',
                certKey: 'gateway',
                certSans: [$gatewayWireGuardAddress, 'gateway'],
                peerIdentityMap: $this->canonicalPeerIdentityMap($containers, $networkPlan),
            );
        } else {
            E2EGatewayApi::start($gateway, "docker-build-{$kind->value}", gatewayIp: $gatewayWireGuardAddress);
        }
        $this->persistRuntimeSource($gateway->name(), 'gateway');

        E2EGatewayApi::waitForGatewayApi($operator, 'orbit', $key, $gatewayReachabilityHost);
        $this->configureClientCliGateways($containers, $mode, $networkPlan);

        $this->seedRemoteShellSshAccess($gateway, $containers);
        $this->seedRemoteShellAgentSshAccess($gateway, $containers);
        $this->waitForManagedSshHostKeys($gateway, $containers, $networkPlan);

        if (isset($containers['dev'])) {
            $host = $this->hostForRole('dev', $networkPlan, $mode);
            $hostKeyHost = $this->hostKeyHostOption('dev', $networkPlan, $mode);
            $wireGuardAddress = $this->wireGuardAddressForRole('dev', $networkPlan, $mode);

            E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                'cd /home/orbit/orbit && orbit orbit:internal:bake-app-node app-dev-1 --role=app-dev --host=%s%s --wireguard-address=%s --tld=test --gateway-endpoint=%s --user=orbit',
                $host,
                $hostKeyHost,
                $wireGuardAddress,
                $gatewayEndpoint,
            ), timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
            $this->seedAppdevDatabaseAndRedis($gateway, $key);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }

        if (isset($containers['ingress'])) {
            $host = $this->hostForRole('ingress', $networkPlan, $mode);
            $hostKeyHost = $this->hostKeyHostOption('ingress', $networkPlan, $mode);
            $wireGuardAddress = $this->wireGuardAddressForRole('ingress', $networkPlan, $mode);

            E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                'cd /home/orbit/orbit && orbit orbit:internal:bake-ingress-node edge-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                $host,
                $hostKeyHost,
                $wireGuardAddress,
                $gatewayEndpoint,
            ), timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }

        if (isset($containers['prod'])) {
            $host = $this->hostForRole('prod', $networkPlan, $mode);
            $hostKeyHost = $this->hostKeyHostOption('prod', $networkPlan, $mode);
            $wireGuardAddress = $this->wireGuardAddressForRole('prod', $networkPlan, $mode);
            $prodHostsIngress = E2EPreparedTopology::prodHostsIngressRole($kind) && ! isset($containers['ingress']);

            if ($prodHostsIngress) {
                E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                    'cd /home/orbit/orbit && orbit orbit:internal:bake-ingress-node app-prod-1 --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit',
                    $host,
                    $hostKeyHost,
                    $wireGuardAddress,
                    $gatewayEndpoint,
                ), timeoutSeconds: 120);
                $this->refreshRuntimeSource($gateway->name(), 'gateway');
            }

            $ingressNode = match (true) {
                isset($containers['ingress']) => 'edge-1',
                $prodHostsIngress => 'app-prod-1',
                default => null,
            };
            $ingress = $ingressNode !== null ? " --ingress-node={$ingressNode}" : '';

            E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                'cd /home/orbit/orbit && orbit orbit:internal:bake-app-node app-prod-1 --role=app-prod --host=%s%s --wireguard-address=%s --gateway-endpoint=%s --user=orbit%s',
                $host,
                $hostKeyHost,
                $wireGuardAddress,
                $gatewayEndpoint,
                $ingress,
            ), timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }

        if (isset($containers['agent'])) {
            $host = $this->hostForRole('agent', $networkPlan, $mode);
            $hostKeyHost = $this->hostKeyHostOption('agent', $networkPlan, $mode);
            $wireGuardAddress = $this->wireGuardAddressForRole('agent', $networkPlan, $mode);

            E2ECommand::ssh($gateway, 'orbit', $key, sprintf(
                'cd /home/orbit/orbit && orbit orbit:internal:bake-agent-node agent-1 --host=%s%s --wireguard-address=%s --tld=agent --gateway-endpoint=%s --user=orbit',
                $host,
                $hostKeyHost,
                $wireGuardAddress,
                $gatewayEndpoint,
            ), timeoutSeconds: 120);
            $this->refreshRuntimeSource($gateway->name(), 'gateway');
        }
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function configureClientCliGateways(array $containers, string $mode, DockerTopologyNetworkPlan $networkPlan): void
    {
        foreach ($containers as $role => $container) {
            if ($role === 'gateway') {
                continue;
            }

            $this->mustRun(
                sprintf(
                    'docker exec %s sh -lc %s',
                    escapeshellarg($container->name()),
                    escapeshellarg($this->configureClientCliGatewayCommand($role, $mode, $networkPlan)),
                ),
                "Could not configure {$role} CLI gateway endpoint",
                timeoutSeconds: 60,
            );
        }
    }

    private function configureClientCliGatewayCommand(string $role, string $mode, DockerTopologyNetworkPlan $networkPlan): string
    {
        $sourcePath = $this->orbitPathForRole($role);
        $user = $this->userForRole($role);
        $gatewayUrl = 'http://'.$this->gatewayEndpoint($networkPlan, $mode);

        return implode(' && ', [
            sprintf('cd %s', escapeshellarg("{$sourcePath}/apps/cli")),
            'touch .env',
            "grep -Ev '^(ORBIT_GATEWAY_URL|ORBIT_GATEWAY_IDENTITY)=' .env > .env.tmp || true",
            'mv .env.tmp .env',
            sprintf("printf 'ORBIT_GATEWAY_URL=%%s\\n' %s >> .env", escapeshellarg($gatewayUrl)),
            sprintf('chown %s:%s %s', escapeshellarg($user), escapeshellarg($user), escapeshellarg("{$sourcePath}/apps/cli/.env")),
            sprintf('cd %s', escapeshellarg($sourcePath)),
            'orbit tinker --execute='.escapeshellarg($this->clientGatewaySettingsPhp($mode, $networkPlan)),
        ]);
    }

    private function clientGatewaySettingsPhp(string $mode, DockerTopologyNetworkPlan $networkPlan): string
    {
        $gatewayIp = $this->wireGuardAddressForRole('gateway', $networkPlan, $mode);
        $gatewayUrl = $mode === 'dns-alias'
            ? 'https://gateway'
            : "https://{$gatewayIp}";
        $gatewayCaUrl = $mode === 'dns-alias'
            ? 'http://gateway/api/ca/root'
            : "http://{$gatewayIp}/api/ca/root";

        return <<<PHP
if (\\Illuminate\\Support\\Facades\\Schema::hasTable('local_gateway_settings')) {
    \$rootCa = null;
    \$response = @file_get_contents('{$gatewayCaUrl}', false, stream_context_create([
        'http' => ['timeout' => 5],
    ]));

    if (is_string(\$response) && \$response !== '') {
        \$decoded = json_decode(\$response, true);
        \$rootCa = is_array(\$decoded)
            ? (\$decoded['success']['data']['root_ca'] ?? \$decoded['data']['root_ca'] ?? null)
            : \$response;
    }

    \$caSha256 = null;
    \$caPemPath = null;

    if (is_string(\$rootCa)
        && str_contains(\$rootCa, '-----BEGIN CERTIFICATE-----')
        && str_contains(\$rootCa, '-----END CERTIFICATE-----')) {
        \$caPemPath = storage_path('app/orbit/gateway-ca/orbit.crt');
        \\Illuminate\\Support\\Facades\\File::ensureDirectoryExists(dirname(\$caPemPath));
        \\Illuminate\\Support\\Facades\\File::put(\$caPemPath, \$rootCa);
        \$caSha256 = hash('sha256', \$rootCa);
    }

    \$settings = \\App\\Models\\LocalGatewaySettings::current();
    \$settings->gateway_url = '{$gatewayUrl}';
    \$settings->gateway_wg_ip = '{$gatewayIp}';

    if (\$caSha256 !== null && \$caPemPath !== null) {
        \$settings->ca_sha256 = \$caSha256;
        \$settings->ca_pem_path = \$caPemPath;
        \$settings->trusted_at = now();
    }

    \$settings->save();
}
PHP;
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

        throw new RuntimeException(trim("SSH command failed: cd /home/orbit/orbit && orbit doctor --node=gateway --family=schedule --restore --json\n".$last->output().$last->errorOutput()));
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
    private function waitForManagedSshHostKeys(DockerBuildInstance $gateway, array $containers, DockerTopologyNetworkPlan $networkPlan): void
    {
        $targets = [];

        foreach ([...$this->managedSshRoles(), 'agent'] as $role) {
            if (! isset($containers[$role])) {
                continue;
            }

            $targets[] = $this->containerIpForRole($role, $networkPlan);
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

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     * @return array<string, string>
     */
    private function canonicalPeerIdentityMap(array $containers, DockerTopologyNetworkPlan $networkPlan): array
    {
        $map = [];

        foreach (array_keys($containers) as $role) {
            $map[$networkPlan->ipForRole($role)] = $this->canonicalWireGuardAddressForRole($role);
        }

        return $map;
    }

    private function hostForRole(string $role, DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? $role
            : $networkPlan->ipForRole($role);
    }

    private function hostKeyHostOption(string $role, DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? ' --host-key-host='.$this->containerIpForRole($role, $networkPlan)
            : '';
    }

    private function containerIpForRole(string $role, DockerTopologyNetworkPlan $networkPlan): string
    {
        return $networkPlan->ipForRole($role);
    }

    private function wireGuardAddressForRole(string $role, DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? $this->canonicalWireGuardAddressForRole($role)
            : $networkPlan->ipForRole($role);
    }

    private function gatewayEndpoint(DockerTopologyNetworkPlan $networkPlan, string $mode): string
    {
        return $mode === 'dns-alias'
            ? 'gateway'
            : $networkPlan->ipForRole('gateway');
    }

    private function canonicalWireGuardAddressForRole(string $role): string
    {
        return match ($role) {
            'gateway' => '10.6.0.2',
            'operator', 'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            'agent' => '10.6.0.6',
            'ingress' => '10.6.0.7',
            default => throw new RuntimeException("Unknown Docker topology role {$role}."),
        };
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
        $paths = [
            base_path('composer.lock'),
            base_path('apps/cli/composer.lock'),
            base_path('apps/gateway/composer.lock'),
        ];
        $context = hash_init('sha256');
        $found = false;

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $found = true;
            hash_update($context, $path);
            hash_update($context, file_get_contents($path) ?: '');
        }

        return $found ? hash_final($context) : 'missing';
    }

    private function certSanSetForMode(string $mode, DockerTopologyNetworkPlan $networkPlan): string
    {
        return $mode === 'dns-alias'
            ? 'DNS:gateway,IP:10.6.0.2'
            : "IP:{$networkPlan->ipForRole('gateway')}";
    }

    /**
     * @return list<string>
     */
    private function managedContainerNames(string $nodeContainer, string $role): array
    {
        $names = [
            "{$nodeContainer}-orbit-caddy",
            $nodeContainer,
        ];

        if (self::roleUsesRuntimeSibling($role)) {
            array_unshift($names, $this->runtimeContainerName($nodeContainer));
        } else {
            array_unshift($names, $this->composerHelperContainerName($nodeContainer));
        }

        return $names;
    }

    private function runtimeContainerName(string $nodeContainer): string
    {
        return "{$nodeContainer}-orbit-runtime";
    }

    private function composerHelperContainerName(string $nodeContainer): string
    {
        return "{$nodeContainer}-composer";
    }

    /**
     * @return list<string>
     */
    private function managedSshRoles(): array
    {
        return ['dev', 'prod', 'ingress'];
    }

    /**
     * @param  array<string, DockerBuildInstance>  $containers
     */
    private function hasManagedSshRole(array $containers): bool
    {
        return array_any($this->managedSshRoles(), fn (string $role): bool => isset($containers[$role]));
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
                "{$this->name}-orbit-runtime",
                "{$this->name}-orbit-caddy",
                $this->name,
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
