<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class E2ECurrentCheckout
{
    private static ?string $cachedArchive = null;

    private static bool $cachedArchiveIsShared = false;

    /** @var array<string, string> */
    private static array $cachedBasePaths = [];

    /** @var (\Closure(): float)|null */
    private static ?\Closure $nowResolver = null;

    /**
     * Install the current local checkout into selected roles of an acquired
     * topology. This is the branch-overlay helper for E2E tests: the topology
     * is cloned from stable prepared templates, then each selected role gets a
     * current-worktree path under /home/<user>/orbit-current*.
     *
     * @param  list<string>|null  $roles
     * @param  array<string, string>  $users
     * @return array<string, string>
     */
    public static function installOnTopology(E2ETopologyLease $topology, ?array $roles = null, array $users = [], ?E2EPhaseTimer $timer = null): array
    {
        $roles ??= self::availableTopologyRoles($topology);

        $paths = [];

        foreach ($roles as $role) {
            [$instance, $user, $seedFrom] = self::topologyRoleTarget($topology, $role, $users);
            $executorNodeIdentity = self::topologyRoleNodeIdentity($role);
            $hostLauncher = self::topologyRoleUsesHostLauncher($role);
            $refreshGatewayHostKeys = $role === 'gateway'
                ? function (string $remotePath) use ($instance, $user, $topology, $timer): void {
                    self::refreshGatewayHostKeys($instance, $user, $topology->sshKeyPair(), $remotePath, $timer);
                }
            : null;

            $paths[$role] = self::install(
                $instance,
                $user,
                $topology->sshKeyPair(),
                seedFrom: $seedFrom,
                timer: $timer,
                afterBaseInstall: $refreshGatewayHostKeys,
                afterInstall: $refreshGatewayHostKeys,
                executorNodeIdentity: $executorNodeIdentity,
                hostLauncher: $hostLauncher,
            );
        }

        return $paths;
    }

    public static function install(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom = null, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
    {
        if (self::checkoutCacheEnabled()) {
            return self::installFromCachedBase($instance, $user, $keyPair, $seedFrom, $timer, $afterBaseInstall, $afterInstall, $executorNodeIdentity, $hostLauncher);
        }

        $remotePath = "/home/{$user}/orbit-current";
        $tarball = $timer?->measure('checkout.archive', fn (): string => self::buildArchive()) ?? self::buildArchive();

        try {
            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));
            self::runInstallPhases($instance, $user, $keyPair, $remotePath, $seedFrom, $timer, $hostLauncher);
            self::activateCurrentCheckout($instance, $remotePath, $executorNodeIdentity, $hostLauncher);
            $afterInstall?->__invoke($remotePath);

            return $remotePath;
        } finally {
            if (is_file($tarball)) {
                @unlink($tarball);
            }
        }
    }

    public static function flushCache(): void
    {
        self::$cachedBasePaths = [];

        if (self::$cachedArchive !== null && ! self::$cachedArchiveIsShared && is_file(self::$cachedArchive)) {
            @unlink(self::$cachedArchive);
        }

        self::$cachedArchive = null;
        self::$cachedArchiveIsShared = false;
    }

    public static function useNowResolverForTests(?callable $resolver): void
    {
        self::$nowResolver = $resolver !== null ? \Closure::fromCallable($resolver) : null;
    }

    public static function orbitWrapperScript(string $checkout, bool $dockerRuntime, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
    {
        if ($dockerRuntime) {
            $executorIdentityExport = $executorNodeIdentity === null
                ? ':'
                : 'export ORBIT_NODE_IDENTITY="${ORBIT_NODE_IDENTITY:-'.self::escapeDoubleQuotedShellValue($executorNodeIdentity).'}"';
            $hostLauncherFlag = $hostLauncher ? '1' : '0';

            return implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'checkout='.escapeshellarg($checkout),
                "if [[ '{$hostLauncherFlag}' == '1' ]]; then",
                '    export ORBIT_REPO="${checkout}"',
                '    export ORBIT_HOST_CWD="${ORBIT_HOST_CWD:-$PWD}"',
                "    {$executorIdentityExport}",
                '    exec "${checkout}/bin/orbit" "$@"',
                'fi',
                'runtime_container="${ORBIT_RUNTIME_CONTAINER:-orbit-runtime}"',
                'runtime_workdir="${ORBIT_HOST_CWD:-$PWD}"',
                'env_args=(',
                '    --env "ORBIT_HOST_CWD=${runtime_workdir}"',
                '    --env '.escapeshellarg("ORBIT_SOURCE_PATH={$checkout}"),
                ')',
                'if [ -n "${ORBIT_IS_GATEWAY:-}" ]; then',
                '    env_args+=(--env "ORBIT_IS_GATEWAY=${ORBIT_IS_GATEWAY}")',
                'fi',
                'if [ -n "${ORBIT_E2E_DOCKER_NETWORK:-}" ]; then',
                '    sudo docker network connect "${ORBIT_E2E_DOCKER_NETWORK}" "${runtime_container}" >/dev/null 2>&1 || true',
                'fi',
                'exec sudo docker exec \\',
                '    "${env_args[@]}" \\',
                '    --workdir "${runtime_workdir}" \\',
                '    "${runtime_container}" \\',
                '    php '.escapeshellarg(self::gatewayArtisanPath($checkout)).' "$@"',
                '',
            ]);
        }

        $php = 'p'.'hp';

        return "#!/usr/bin/env bash\nset -euo pipefail\nexec {$php} ".escapeshellarg(self::gatewayArtisanPath($checkout)).' "$@"'."\n";
    }

    private static function escapeDoubleQuotedShellValue(string $value): string
    {
        return str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value);
    }

    private static function gatewayArtisanPath(string $checkout): string
    {
        return "{$checkout}/apps/gateway/artisan";
    }

    private static function checkoutCacheEnabled(): bool
    {
        $value = getenv('ORBIT_E2E_CHECKOUT_CACHE');

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'yes', 'process'], true);
    }

    private static function installFromCachedBase(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
    {
        $cacheKey = implode('|', [$instance->name(), $user, $seedFrom ?? '']);
        $basePath = "/home/{$user}/orbit-current-base-".substr(sha1($cacheKey), 0, 10);
        $reusedBase = isset(self::$cachedBasePaths[$cacheKey]);

        if (! $reusedBase) {
            $tarball = self::cachedArchive($timer);

            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));

            self::runInstallPhases($instance, $user, $keyPair, $basePath, $seedFrom, $timer, $hostLauncher);
            self::activateCurrentCheckout($instance, $basePath, $executorNodeIdentity, $hostLauncher);
            $afterBaseInstall?->__invoke($basePath);

            self::$cachedBasePaths[$cacheKey] = $basePath;
        }

        $remotePath = "/home/{$user}/orbit-current-".bin2hex(random_bytes(4));

        $cloneCheckout = function () use ($instance, $user, $keyPair, $remotePath, $cacheKey): void {
            E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::cloneCachedCheckoutCommand(self::$cachedBasePaths[$cacheKey], $remotePath),
                timeoutSeconds: 120,
            );
        };

        if ($reusedBase && $timer !== null) {
            $timer->measure('checkout.cache-clone', $cloneCheckout);
        } else {
            $cloneCheckout();
        }

        self::activateCurrentCheckout($instance, $remotePath, $executorNodeIdentity, $hostLauncher);
        $afterInstall?->__invoke($remotePath);

        return $remotePath;
    }

    private static function activateCurrentCheckout(E2EInstance $instance, string $remotePath, ?string $executorNodeIdentity = null, bool $hostLauncher = false): void
    {
        if (! self::usesDockerRuntime($instance)) {
            return;
        }

        $tmpScript = tempnam(sys_get_temp_dir(), 'orbit-current-');

        if (! is_string($tmpScript)) {
            throw new RuntimeException('Could not create temporary current-checkout orbit wrapper.');
        }

        try {
            if (file_put_contents($tmpScript, self::orbitWrapperScript($remotePath, dockerRuntime: true, executorNodeIdentity: $executorNodeIdentity, hostLauncher: $hostLauncher)) === false) {
                throw new RuntimeException("Could not write current-checkout orbit wrapper to {$tmpScript}.");
            }

            if (! chmod($tmpScript, 0755)) {
                throw new RuntimeException("Could not make current-checkout orbit wrapper executable at {$tmpScript}.");
            }

            $instance->copyFileToInstance($tmpScript, '/usr/local/bin/orbit');
        } finally {
            @unlink($tmpScript);
        }
    }

    private static function runInstallPhases(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?string $seedFrom, ?E2EPhaseTimer $timer, bool $hostLauncher = false): void
    {
        $vendorSourcePath = $seedFrom ?? "/home/{$user}/orbit";
        $deadline = self::now() + 600.0;
        $dockerTopology = self::usesDockerRuntime($instance);
        $dockerRuntimeContainer = $dockerTopology ? self::dockerRuntimeContainerName($instance) : null;
        $runBootstrapInDockerRuntime = $dockerTopology && ! $hostLauncher;
        $requiresRootApplication = ! ($dockerTopology && $hostLauncher);

        self::runInstallPhase(
            $timer,
            'checkout.extract',
            fn (): string => self::extractCheckoutCommand($remotePath),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.vendor',
            fn (): string => self::vendorInstallCommand($remotePath, $vendorSourcePath, $dockerTopology, $dockerRuntimeContainer, $requiresRootApplication),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.runtime-state',
            fn (): string => self::runtimeStateCommand($remotePath, $seedFrom, $dockerTopology, $dockerRuntimeContainer, $runBootstrapInDockerRuntime),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.migrate',
            fn (): string => self::migrateCommand($remotePath, $dockerTopology, $dockerRuntimeContainer, $runBootstrapInDockerRuntime),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );
    }

    private static function runInstallPhase(?E2EPhaseTimer $timer, string $phase, callable $command, E2EInstance $instance, string $user, SshKeyPair $keyPair, float $deadline): void
    {
        $execute = function () use ($command, $instance, $user, $keyPair, $deadline): void {
            E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                $command(),
                timeoutSeconds: self::remainingInstallTimeoutSeconds($deadline),
            );
        };

        if ($timer === null) {
            $execute();

            return;
        }

        $timer->measure($phase, $execute);
    }

    private static function remainingInstallTimeoutSeconds(float $deadline): int
    {
        return max(1, (int) ceil($deadline - self::now()));
    }

    private static function runTimed(?E2EPhaseTimer $timer, string $phase, callable $callback): mixed
    {
        if ($timer === null) {
            return $callback();
        }

        return $timer->measure($phase, $callback);
    }

    private static function extractCheckoutCommand(string $remotePath): string
    {
        return implode(' && ', [
            'sudo rm -rf '.escapeshellarg($remotePath),
            'mkdir -p '.escapeshellarg($remotePath),
            'tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C '.escapeshellarg($remotePath),
            'sudo rm -f /tmp/orbit-current.tar.gz',
        ]);
    }

    private static function vendorInstallCommand(string $remotePath, string $vendorSourcePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, bool $requiresRootApplication = true): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            $dockerTopology
                ? self::reuseRuntimeDependenciesCommand($vendorSourcePath, $remotePath, $dockerRuntimeContainer, $requiresRootApplication)
                : self::installComposerDependenciesCommand($vendorSourcePath),
        ]);
    }

    private static function reuseRuntimeDependenciesCommand(string $vendorSourcePath, string $remotePath, ?string $dockerRuntimeContainer = null, bool $requiresRootApplication = true): string
    {
        $sourceVendor = escapeshellarg("{$vendorSourcePath}/vendor");
        $sourceAutoload = escapeshellarg("{$vendorSourcePath}/vendor/autoload.php");
        $sourceCliVendor = escapeshellarg("{$vendorSourcePath}/apps/cli/vendor");
        $sourceCliEnv = escapeshellarg("{$vendorSourcePath}/apps/cli/.env");
        $commands = [];

        if ($requiresRootApplication) {
            $commands[] = "if [ -f {$sourceAutoload} ] && [ -d {$sourceVendor} ]; then rm -rf vendor && ln -s {$sourceVendor} vendor; else ".self::runtimeComposerInstallCommand($remotePath, $dockerRuntimeContainer).'; fi';
        } else {
            $commands[] = "if [ -f {$sourceAutoload} ] && [ -d {$sourceVendor} ]; then rm -rf vendor && ln -s {$sourceVendor} vendor; else echo 'Prepared root vendor dependencies are required for Docker host-launcher checkout.' >&2; exit 127; fi";
        }

        $commands[] = "if [ -d {$sourceCliVendor} ]; then rm -rf apps/cli/vendor && ln -s {$sourceCliVendor} apps/cli/vendor; fi";
        $commands[] = "if [ -f {$sourceCliEnv} ]; then cp {$sourceCliEnv} apps/cli/.env; fi";

        return implode(' && ', $commands);
    }

    private static function runtimeComposerInstallCommand(string $remotePath, ?string $dockerRuntimeContainer): string
    {
        if ($dockerRuntimeContainer === null) {
            return 'composer install --no-interaction --prefer-dist --optimize-autoloader';
        }

        $environment = [
            "ORBIT_SOURCE_PATH={$remotePath}",
            'COMPOSER_CACHE_DIR=/tmp/orbit-composer-cache',
            'COMPOSER_HOME=/tmp/orbit-composer-home',
            'COMPOSER_PROCESS_TIMEOUT=1200',
            'COMPOSER_ALLOW_SUPERUSER=1',
        ];

        $environmentFlags = implode(' ', array_map(
            fn (string $value): string => '--env '.escapeshellarg($value),
            $environment,
        ));

        $composerConfig = sprintf(
            'mkdir -p %s %s && printf %%s %s > %s',
            escapeshellarg('/tmp/orbit-composer-cache'),
            escapeshellarg('/tmp/orbit-composer-home'),
            escapeshellarg(json_encode([
                'config' => [
                    'cache-dir' => '/tmp/orbit-composer-cache',
                    'github-protocols' => ['https'],
                ],
            ], JSON_THROW_ON_ERROR)),
            escapeshellarg('/tmp/orbit-composer-home/config.json'),
        );

        return sprintf(
            'sudo docker exec %s --workdir %s %s sh -lc %s',
            $environmentFlags,
            escapeshellarg($remotePath),
            escapeshellarg($dockerRuntimeContainer),
            escapeshellarg($composerConfig.' && (git config --global --add safe.directory '.escapeshellarg('*').' >/dev/null 2>&1 || true) && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress'),
        );
    }

    private static function installComposerDependenciesCommand(string $vendorSourcePath): string
    {
        $sourceLock = escapeshellarg("{$vendorSourcePath}/composer.lock");
        $sourceAutoload = escapeshellarg("{$vendorSourcePath}/vendor/autoload.php");
        $sourceComposer = escapeshellarg("{$vendorSourcePath}/vendor/composer");
        $sourceVendor = escapeshellarg("{$vendorSourcePath}/vendor");
        $reuseVendor = implode(' && ', [
            'rm -rf vendor',
            'mkdir -p vendor',
            "find {$sourceVendor} -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec ln -s {} vendor/ \\;",
            "cp -a {$sourceComposer} vendor/composer",
            "cp {$sourceAutoload} vendor/autoload.php",
            'if command -v composer >/dev/null 2>&1; then composer dump-autoload --no-interaction --optimize; fi',
        ]);

        return "if [ -f {$sourceAutoload} ] && [ -d {$sourceComposer} ] && [ -f {$sourceLock} ] && cmp -s {$sourceLock} composer.lock; then {$reuseVendor}; elif command -v composer >/dev/null 2>&1; then composer install --no-interaction --prefer-dist --optimize-autoloader; else echo 'Composer is not installed and prepared vendor dependencies could not be reused.' >&2; exit 127; fi";
    }

    private static function prepareRuntimeStateCommand(?string $seedFrom, bool $dockerTopology = false, ?string $remotePath = null, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null): string
    {
        $runArtisanInDockerRuntime ??= $dockerTopology;
        $runtimeDirectories = 'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs';

        if ($seedFrom === null) {
            return "cp .env.example .env && mkdir -p apps/gateway/database && touch apps/gateway/database/database.sqlite && {$runtimeDirectories} && ".self::dockerTopologyProviderEnvCommand($dockerTopology).' && '.self::artisanCommand('key:generate --ansi', $runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer);
        }

        $seedEnv = escapeshellarg("{$seedFrom}/.env");
        $seedDatabase = escapeshellarg("{$seedFrom}/apps/gateway/database/database.sqlite");
        $seedStorageApp = escapeshellarg("{$seedFrom}/storage/app");

        return implode(' && ', [
            "if [ -f {$seedEnv} ]; then cp {$seedEnv} .env; else cp .env.example .env; fi",
            'mkdir -p apps/gateway/database',
            "if [ -f {$seedDatabase} ]; then rm -f apps/gateway/database/database.sqlite apps/gateway/database/database.sqlite-* && cp {$seedDatabase} apps/gateway/database/database.sqlite; else touch apps/gateway/database/database.sqlite; fi",
            "if [ -d {$seedStorageApp} ]; then mkdir -p storage && rm -rf storage/app && cp -a {$seedStorageApp} storage/app; fi",
            $runtimeDirectories,
            self::dockerTopologyProviderEnvCommand($dockerTopology),
            self::appKeyCommand($runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer),
        ]);
    }

    private static function appKeyCommand(bool $dockerTopology = false, ?string $remotePath = null, ?string $dockerRuntimeContainer = null): string
    {
        return implode(' && ', [
            "(grep -q '^APP_KEY=' .env || printf '%s\\n' 'APP_KEY=' >> .env)",
            "(grep -Eq '^APP_KEY=base64:.+' .env || ".self::artisanCommand('key:generate --force --no-interaction --ansi', $dockerTopology, $remotePath, $dockerRuntimeContainer).')',
            "grep -Eq '^APP_KEY=base64:.+' .env",
        ]);
    }

    private static function dockerTopologyProviderEnvCommand(bool $dockerTopology): string
    {
        if (! $dockerTopology) {
            return ':';
        }

        return implode(' && ', [
            "grep -Ev '^(ORBIT_E2E_TOPOLOGY_PROVIDER)=' .env > .env.tmp",
            'mv .env.tmp .env',
            "printf '%s\\n' 'ORBIT_E2E_TOPOLOGY_PROVIDER=docker' >> .env",
        ]);
    }

    private static function runtimeStateCommand(string $remotePath, ?string $seedFrom, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::prepareRuntimeStateCommand($seedFrom, $dockerTopology, $remotePath, $dockerRuntimeContainer, $runArtisanInDockerRuntime),
        ]);
    }

    private static function migrateCommand(string $remotePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null): string
    {
        $runArtisanInDockerRuntime ??= $dockerTopology;
        $commands = [
            'cd '.escapeshellarg($remotePath),
            self::artisanCommand('migrate --force --ansi', $runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer),
        ];

        if ($dockerTopology) {
            $commands[] = self::dockerGatewaySettingsCommand($remotePath, $dockerRuntimeContainer, $runArtisanInDockerRuntime);
        }

        return implode(' && ', $commands);
    }

    private static function dockerGatewaySettingsCommand(string $remotePath, ?string $dockerRuntimeContainer, bool $runArtisanInDockerRuntime = true): string
    {
        $php = <<<'PHP'
if (\Illuminate\Support\Facades\Schema::hasTable('local_gateway_settings')) {
    $rootCa = null;

    if (gethostbyname('gateway') !== 'gateway') {
        $response = @file_get_contents('http://gateway/api/ca/root', false, stream_context_create([
            'http' => ['timeout' => 5],
        ]));

        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            $rootCa = is_array($decoded)
                ? ($decoded['success']['data']['root_ca'] ?? $decoded['data']['root_ca'] ?? null)
                : $response;
        }
    }

    $caSha256 = null;
    $caPemPath = null;

    if (is_string($rootCa)
        && str_contains($rootCa, '-----BEGIN CERTIFICATE-----')
        && str_contains($rootCa, '-----END CERTIFICATE-----')) {
        $caPemPath = storage_path('app/orbit/gateway-ca/orbit.crt');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($caPemPath));
        \Illuminate\Support\Facades\File::put($caPemPath, $rootCa);
        $caSha256 = hash('sha256', $rootCa);
    }

    $settings = \App\Models\LocalGatewaySettings::current();
    $settings->gateway_url = 'https://gateway';
    $settings->gateway_wg_ip = '10.6.0.2';

    if ($caSha256 !== null && $caPemPath !== null) {
        $settings->ca_sha256 = $caSha256;
        $settings->ca_pem_path = $caPemPath;
        $settings->trusted_at = now();
    }

    $settings->save();
}
PHP;

        return self::artisanCommand('tinker --execute='.escapeshellarg($php), $runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer);
    }

    private static function refreshGatewayHostKeys(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?E2EPhaseTimer $timer): void
    {
        self::runTimed(
            $timer,
            'checkout.host-keys',
            fn () => E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::hostKeyRefreshCommand(
                    $remotePath,
                    self::usesDockerRuntime($instance),
                    self::usesDockerRuntime($instance) ? self::dockerRuntimeContainerName($instance) : null,
                ),
                timeoutSeconds: 120,
            ),
        );
    }

    private static function hostKeyRefreshCommand(string $remotePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::artisanCommand('orbit:internal:pin-node-host-keys --json', $dockerTopology, $remotePath, $dockerRuntimeContainer),
        ]);
    }

    private static function artisanCommand(string $arguments, bool $dockerTopology, ?string $remotePath = null, ?string $dockerRuntimeContainer = null): string
    {
        if (! $dockerTopology) {
            return 'php artisan '.$arguments;
        }

        if ($remotePath === null || $dockerRuntimeContainer === null) {
            return 'orbit '.$arguments;
        }

        return sprintf(
            'sudo docker exec --env %s --workdir %s %s php %s %s',
            escapeshellarg("ORBIT_SOURCE_PATH={$remotePath}"),
            escapeshellarg($remotePath),
            escapeshellarg($dockerRuntimeContainer),
            escapeshellarg(self::gatewayArtisanPath($remotePath)),
            $arguments,
        );
    }

    private static function usesDockerRuntime(E2EInstance $instance): bool
    {
        return $instance instanceof DockerInstance || $instance instanceof DockerBuildInstance;
    }

    private static function dockerRuntimeContainerName(E2EInstance $instance): string
    {
        return $instance->name().'-orbit-runtime';
    }

    private static function cloneCachedCheckoutCommand(string $basePath, string $remotePath): string
    {
        $quotedBasePath = escapeshellarg($basePath);
        $quotedRemotePath = escapeshellarg($remotePath);

        return implode(' && ', [
            "sudo rm -rf {$quotedRemotePath}",
            "mkdir -p {$quotedRemotePath}",
            "cd {$quotedBasePath}",
            "find . -mindepth 1 -maxdepth 1 ! -name vendor ! -name storage ! -name .env -exec sh -c 'target=\$1; shift; for path do cp -al \"\$path\" \"\$target\"/ 2>/dev/null || cp -a --reflink=always \"\$path\" \"\$target\"/ 2>/dev/null || cp -a \"\$path\" \"\$target\"/; done' sh {$quotedRemotePath} {} +",
            self::cloneCachedVendorCommand($basePath, $remotePath),
            "if [ -f {$quotedBasePath}/.env ]; then cp {$quotedBasePath}/.env {$quotedRemotePath}/.env; fi",
            "mkdir -p {$quotedRemotePath}/apps/gateway/database",
            "if [ -f {$quotedBasePath}/apps/gateway/database/database.sqlite ]; then rm -f {$quotedRemotePath}/apps/gateway/database/database.sqlite {$quotedRemotePath}/apps/gateway/database/database.sqlite-* && cp {$quotedBasePath}/apps/gateway/database/database.sqlite {$quotedRemotePath}/apps/gateway/database/database.sqlite; else touch {$quotedRemotePath}/apps/gateway/database/database.sqlite; fi",
            self::cloneCachedStorageCommand($basePath, $remotePath),
        ]);
    }

    private static function cloneCachedVendorCommand(string $basePath, string $remotePath): string
    {
        $baseVendor = escapeshellarg("{$basePath}/vendor");
        $remoteVendor = escapeshellarg("{$remotePath}/vendor");

        return "if [ -d {$baseVendor} ]; then ln -s {$baseVendor} {$remoteVendor}; fi";
    }

    private static function cloneCachedStorageCommand(string $basePath, string $remotePath): string
    {
        $baseStorageApp = escapeshellarg("{$basePath}/storage/app");
        $remoteStorage = escapeshellarg("{$remotePath}/storage");
        $remoteStorageApp = escapeshellarg("{$remotePath}/storage/app");

        return implode(' && ', [
            "mkdir -p {$remoteStorage}/framework/cache/data {$remoteStorage}/framework/sessions {$remoteStorage}/framework/testing {$remoteStorage}/framework/views {$remoteStorage}/logs",
            "if [ -d {$baseStorageApp} ]; then rm -rf {$remoteStorageApp} && cp -a {$baseStorageApp} {$remoteStorageApp}; fi",
        ]);
    }

    /**
     * @return list<string>
     */
    private static function availableTopologyRoles(E2ETopologyLease $topology): array
    {
        $roles = ['operator'];
        $instanceNames = [$topology->operator()->name()];

        if ($topology->gateway() !== null) {
            $roles[] = 'gateway';
            $instanceNames[] = $topology->gateway()->name();
        }

        if ($topology->devApp() !== null) {
            $roles[] = 'dev';
            $instanceNames[] = $topology->devApp()->name();
        }

        if ($topology->prodApp() !== null) {
            $roles[] = 'prod';
            $instanceNames[] = $topology->prodApp()->name();
        }

        if ($topology->agent() !== null) {
            $roles[] = 'agent';
            $instanceNames[] = $topology->agent()->name();
        }

        if ($topology->ingress() !== null && ! in_array($topology->ingress()->name(), $instanceNames, true)) {
            $roles[] = 'ingress';
        }

        return $roles;
    }

    /**
     * @return array{0: E2EInstance, 1: string, 2: string}
     */
    private static function topologyRoleTarget(E2ETopologyLease $topology, string $role, array $users): array
    {
        $operatorUser = $users['operator']
            ?? $users['control']
            ?? E2EConfig::fromEnvironment()->operatorUser;

        return match ($role) {
            'operator', 'control' => [$topology->operator(), $operatorUser, "/home/{$operatorUser}/orbit"],
            'gateway' => self::requiredRole($topology->gateway(), $role, $users['gateway'] ?? 'orbit'),
            'dev' => self::requiredRole($topology->devApp(), $role, $users['dev'] ?? 'orbit'),
            'prod' => self::requiredRole($topology->prodApp(), $role, $users['prod'] ?? 'orbit'),
            'agent' => self::requiredRole($topology->agent(), $role, $users['agent'] ?? 'orbit'),
            'ingress' => self::requiredRole($topology->ingress(), $role, $users[$role] ?? 'orbit'),
            default => throw new RuntimeException("Unknown topology role [{$role}]."),
        };
    }

    private static function topologyRoleNodeIdentity(string $role): ?string
    {
        return match ($role) {
            'operator', 'control' => 'control-1',
            'gateway' => 'gateway',
            'dev' => 'app-dev-1',
            'prod' => 'app-prod-1',
            'agent' => 'agent-1',
            'ingress' => 'edge-1',
            default => null,
        };
    }

    private static function topologyRoleUsesHostLauncher(string $role): bool
    {
        return in_array($role, ['operator', 'control', 'dev', 'prod', 'agent', 'ingress'], true);
    }

    /**
     * @return array{0: E2EInstance, 1: string, 2: string}
     */
    private static function requiredRole(?E2EInstance $instance, string $role, string $user): array
    {
        if ($instance === null) {
            throw new RuntimeException("Topology does not include role [{$role}].");
        }

        return [$instance, $user, "/home/{$user}/orbit"];
    }

    public static function buildArchive(): string
    {
        $tarball = sys_get_temp_dir().'/orbit-current-'.bin2hex(random_bytes(6)).'.tar.gz';
        $attempts = 0;

        do {
            $attempts++;
            $manifest = self::writeArchiveManifest();

            $result = Process::timeout(300)->run(sprintf(
                'COPYFILE_DISABLE=1 tar --null -czf %s -C %s -T %s',
                escapeshellarg($tarball),
                escapeshellarg(base_path()),
                escapeshellarg($manifest),
            ));

            @unlink($manifest);

            if ($result->successful()) {
                break;
            }

            @unlink($tarball);

            if ($attempts < 3 && str_contains($result->errorOutput(), 'Cannot stat')) {
                continue;
            }

            throw new RuntimeException("Failed to build current checkout archive: {$result->errorOutput()}");
        } while ($attempts < 3);

        if (is_file($tarball) && ! @chmod($tarball, 0644)) {
            throw new RuntimeException("Failed to make current checkout archive readable: {$tarball}");
        }

        return $tarball;
    }

    /**
     * @return list<string>
     */
    public static function archiveExcludePatterns(): array
    {
        return [
            './.git',
            './.worktrees',
            './.codex',
            './.cursor',
            './.idea',
            './.nova',
            './.phpunit.cache',
            './.vscode',
            './.zed',
            './.env',
            './.env.e2e',
            './auth.json',
            './build',
            './apps/gateway/database/*.sqlite',
            './apps/gateway/database/*.sqlite-*',
            './node_modules',
            './public/build',
            './public/hot',
            './public/storage',
            './storage/framework/e2e/*',
            './storage/app/orbit/ca/*',
            './storage/app/orbit/certs/*',
            './storage/app/orbit/keys/*',
            './storage/framework/cache/data/*',
            './storage/framework/sessions/*',
            './storage/framework/testing/*',
            './storage/framework/views/*',
            './storage/logs/*',
            './storage/pail',
            './apps/gateway/tests/E2E/.docker-feature-tests/*',
            './vendor',
        ];
    }

    private static function cachedArchive(?E2EPhaseTimer $timer = null): string
    {
        if (self::$cachedArchive !== null) {
            return self::$cachedArchive;
        }

        $directory = self::archiveCacheDirectory();

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create checkout archive cache directory: {$directory}");
        }

        $archive = $directory.'/'.self::treeHash().'.tar.gz';
        $lockPath = "{$archive}.lock";
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException("Failed to open checkout archive cache lock: {$lockPath}");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException("Failed to lock checkout archive cache: {$lockPath}");
            }

            if (! is_file($archive) || filesize($archive) === 0) {
                $temporaryArchive = $timer?->measure('checkout.archive', fn (): string => self::buildArchive()) ?? self::buildArchive();

                if (is_file($temporaryArchive)) {
                    if (! @rename($temporaryArchive, $archive)) {
                        @unlink($temporaryArchive);

                        throw new RuntimeException("Failed to publish checkout archive cache: {$archive}");
                    }

                    if (! @chmod($archive, 0644)) {
                        throw new RuntimeException("Failed to make checkout archive cache readable: {$archive}");
                    }
                } else {
                    touch($archive);
                    chmod($archive, 0644);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::$cachedArchive = $archive;
        self::$cachedArchiveIsShared = true;

        return self::$cachedArchive;
    }

    private static function archiveCacheDirectory(): string
    {
        $configured = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return sys_get_temp_dir().'/orbit-e2e-checkout-archives';
    }

    public static function treeHash(): string
    {
        $manifest = '';

        foreach (self::archiveManifest() as $path) {
            $absolutePath = base_path($path);
            $manifest .= $path."\0".hash_file('sha256', $absolutePath)."\n";
        }

        return substr(hash('sha256', $manifest), 0, 16);
    }

    private static function writeArchiveManifest(): string
    {
        $manifest = tempnam(sys_get_temp_dir(), 'orbit-e2e-archive-manifest-');

        if ($manifest === false) {
            throw new RuntimeException('Failed to create current checkout archive manifest.');
        }

        $contents = implode("\0", self::archiveManifest());

        if ($contents !== '') {
            $contents .= "\0";
        }

        if (file_put_contents($manifest, $contents) === false) {
            @unlink($manifest);

            throw new RuntimeException("Failed to write current checkout archive manifest: {$manifest}");
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private static function archiveManifest(): array
    {
        $output = (string) shell_exec('git ls-files -z --cached --others --exclude-standard 2>/dev/null');
        $paths = array_values(array_filter(explode("\0", $output), fn (string $path): bool => $path !== ''));

        $paths = array_values(array_filter($paths, self::shouldIncludeArchivePath(...)));
        $paths = array_values(array_unique($paths));

        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function shouldIncludeArchivePath(string $path): bool
    {
        $path = self::normalizeArchivePath($path);

        if ($path === '' || ! is_file(base_path($path))) {
            return false;
        }

        return array_all(self::archiveExcludePatterns(), fn (string $pattern): bool => ! self::archivePathMatchesPattern($path, $pattern));
    }

    private static function archivePathMatchesPattern(string $path, string $pattern): bool
    {
        $pattern = self::normalizeArchivePath($pattern);

        if ($pattern === '') {
            return false;
        }

        if (! str_contains($pattern, '*') && ! str_contains($pattern, '?') && ! str_contains($pattern, '[')) {
            return $path === $pattern || str_starts_with($path, "{$pattern}/");
        }

        return fnmatch($pattern, $path);
    }

    private static function normalizeArchivePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }

    private static function copyArchive(string $tarball, E2EInstance $instance): void
    {
        if ($instance instanceof IncusInstance) {
            $instance->copyLocalFileToInstance($tarball, '/tmp/orbit-current.tar.gz');

            return;
        }

        $instance->copyFileToInstance($tarball, '/tmp/orbit-current.tar.gz');
    }

    private static function now(): float
    {
        return self::$nowResolver !== null
            ? (self::$nowResolver)()
            : microtime(true);
    }
}
