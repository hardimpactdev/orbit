<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class E2ECurrentCheckout
{
    private const string GatewayArtisanRelativePath = 'apps/gateway/artisan';

    private const string CurrentCheckoutOperationTokenSecret = 'orbit-e2e-current-checkout-operation-token-secret';

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
        $operationTokenSecret = self::CurrentCheckoutOperationTokenSecret;

        $paths = [];

        foreach ($roles as $role) {
            [$instance, $user, $seedFrom] = self::topologyRoleTarget($topology, $role, $users);
            $executorNodeIdentity = self::topologyRoleNodeIdentity($role);
            $hostLauncher = self::topologyRoleUsesHostLauncher($role);
            $refreshGatewayHostKeys = $role === 'gateway'
                ? function (string $remotePath) use ($instance, $user, $topology, $timer, $hostLauncher): void {
                    self::refreshGatewayHostKeys($instance, $user, $topology->sshKeyPair(), $remotePath, $timer, $hostLauncher);
                }
            : null;
            $refreshLocalGatewaySettings = $role !== 'gateway' && $topology->gateway() !== null && ! self::usesDockerRuntime($instance)
                ? function (string $remotePath) use ($instance, $user, $topology, $timer): void {
                    self::refreshLocalGatewaySettings($instance, $user, $topology->sshKeyPair(), $remotePath, $topology->gatewayApiIp(), $timer);
                }
            : null;
            $afterInstall = ($refreshGatewayHostKeys !== null || $refreshLocalGatewaySettings !== null)
                ? function (string $remotePath) use ($refreshGatewayHostKeys, $refreshLocalGatewaySettings): void {
                    $refreshGatewayHostKeys?->__invoke($remotePath);
                    $refreshLocalGatewaySettings?->__invoke($remotePath);
                }
            : null;

            $paths[$role] = self::install(
                $instance,
                $user,
                $topology->sshKeyPair(),
                seedFrom: $seedFrom,
                timer: $timer,
                afterBaseInstall: $refreshGatewayHostKeys,
                afterInstall: $afterInstall,
                executorNodeIdentity: $executorNodeIdentity,
                hostLauncher: $hostLauncher,
                operationTokenSecret: $operationTokenSecret,
            );
        }

        return $paths;
    }

    public static function install(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom = null, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null, ?string $executorNodeIdentity = null, bool $hostLauncher = false, ?string $operationTokenSecret = null): string
    {
        if (self::checkoutCacheEnabled()) {
            return self::installFromCachedBase($instance, $user, $keyPair, $seedFrom, $timer, $afterBaseInstall, $afterInstall, $executorNodeIdentity, $hostLauncher, $operationTokenSecret);
        }

        $remotePath = "/home/{$user}/orbit-current";
        $tarball = $timer?->measure('checkout.archive', fn (): string => self::buildArchive()) ?? self::buildArchive();

        try {
            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));
            self::runInstallPhases($instance, $user, $keyPair, $remotePath, $seedFrom, $timer, $hostLauncher, $operationTokenSecret);
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
        if ($hostLauncher) {
            $executorIdentityExport = $executorNodeIdentity === null
                ? ':'
                : 'export ORBIT_NODE_IDENTITY="${ORBIT_NODE_IDENTITY:-'.self::escapeDoubleQuotedShellValue($executorNodeIdentity).'}"';

            return implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'checkout='.escapeshellarg($checkout),
                'export ORBIT_REPO="${checkout}"',
                'export ORBIT_HOST_CWD="${ORBIT_HOST_CWD:-$PWD}"',
                $executorIdentityExport,
                'exec "${checkout}/bin/orbit" "$@"',
                '',
            ]);
        }

        if ($dockerRuntime) {
            return implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'checkout='.escapeshellarg($checkout),
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
        return "{$checkout}/".self::GatewayArtisanRelativePath;
    }

    private static function checkoutCacheEnabled(): bool
    {
        $value = getenv('ORBIT_E2E_CHECKOUT_CACHE');

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'yes', 'process'], true);
    }

    private static function installFromCachedBase(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null, ?string $executorNodeIdentity = null, bool $hostLauncher = false, ?string $operationTokenSecret = null): string
    {
        $cacheKey = implode('|', [$instance->name(), $user, $seedFrom ?? '']);
        $basePath = "/home/{$user}/orbit-current-base-".substr(sha1($cacheKey), 0, 10);
        $reusedBase = isset(self::$cachedBasePaths[$cacheKey]);

        if (! $reusedBase) {
            $tarball = self::cachedArchive($timer);

            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));

            self::runInstallPhases($instance, $user, $keyPair, $basePath, $seedFrom, $timer, $hostLauncher, $operationTokenSecret);
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

    private static function runInstallPhases(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?string $seedFrom, ?E2EPhaseTimer $timer, bool $hostLauncher = false, ?string $operationTokenSecret = null): void
    {
        $vendorSourcePath = $seedFrom ?? "/home/{$user}/orbit";
        $deadline = self::now() + 600.0;
        $dockerTopology = self::usesDockerRuntime($instance);
        $dockerRuntimeContainer = $dockerTopology ? self::dockerRuntimeContainerName($instance) : null;
        $runBootstrapInDockerRuntime = $dockerTopology && ! $hostLauncher;
        $requiresGatewayApplication = ! ($dockerTopology && $hostLauncher);
        $vendorRuntimeContainer = $runBootstrapInDockerRuntime ? $dockerRuntimeContainer : null;

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
            fn (): string => self::vendorInstallCommand($remotePath, $vendorSourcePath, $dockerTopology, $vendorRuntimeContainer, $requiresGatewayApplication),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.runtime-state',
            fn (): string => self::runtimeStateCommand($remotePath, $seedFrom, $dockerTopology, $dockerRuntimeContainer, $runBootstrapInDockerRuntime, $operationTokenSecret),
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

    private static function vendorInstallCommand(string $remotePath, string $vendorSourcePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, bool $requiresGatewayApplication = true): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            $dockerTopology
                ? self::reuseRuntimeDependenciesCommand($vendorSourcePath, $remotePath, $dockerRuntimeContainer, $requiresGatewayApplication)
                : self::installComposerDependenciesCommand($vendorSourcePath),
        ]);
    }

    private static function reuseRuntimeDependenciesCommand(string $vendorSourcePath, string $remotePath, ?string $dockerRuntimeContainer = null, bool $requiresGatewayApplication = true): string
    {
        $gatewayFallback = $requiresGatewayApplication
            ? self::runtimeComposerInstallCommand($remotePath, $dockerRuntimeContainer)
            : "echo 'Prepared gateway vendor dependencies are required for Docker host-launcher checkout.' >&2; exit 127";
        $sourceCliEnv = escapeshellarg("{$vendorSourcePath}/apps/cli/.env");

        $commands = [
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/gateway',
                vendorSourcePath: $vendorSourcePath,
                fallbackClause: "else {$gatewayFallback}",
            ),
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/cli',
                vendorSourcePath: $vendorSourcePath,
                fallbackClause: "else echo 'Prepared CLI vendor dependencies are required for Docker current checkout.' >&2; exit 127",
            ),
        ];
        $commands[] = "if [ -f {$sourceCliEnv} ]; then cp {$sourceCliEnv} apps/cli/.env; fi";

        return implode(' && ', $commands);
    }

    private static function runtimeComposerInstallCommand(string $remotePath, ?string $dockerRuntimeContainer): string
    {
        if ($dockerRuntimeContainer === null) {
            return 'composer --working-dir=apps/gateway install --no-interaction --prefer-dist --optimize-autoloader';
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
            escapeshellarg("{$remotePath}/apps/gateway"),
            escapeshellarg($dockerRuntimeContainer),
            escapeshellarg($composerConfig.' && (git config --global --add safe.directory '.escapeshellarg('*').' >/dev/null 2>&1 || true) && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress'),
        );
    }

    private static function installComposerDependenciesCommand(string $vendorSourcePath): string
    {
        return implode(' && ', [
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/gateway',
                vendorSourcePath: $vendorSourcePath,
                fallbackClause: "elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/gateway install --no-interaction --prefer-dist --optimize-autoloader; else echo 'Gateway Composer dependencies are not installed and prepared vendor dependencies could not be reused.' >&2; exit 127",
            ),
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/cli',
                vendorSourcePath: $vendorSourcePath,
                fallbackClause: "elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/cli install --no-interaction --prefer-dist --optimize-autoloader; else echo 'CLI Composer dependencies are not installed and prepared vendor dependencies could not be reused.' >&2; exit 127",
            ),
        ]);
    }

    private static function reusePreparedVendorWithLocalAutoloadCommand(
        string $appPath,
        string $vendorSourcePath,
        string $fallbackClause,
    ): string {
        $sourceLock = escapeshellarg("{$vendorSourcePath}/{$appPath}/composer.lock");
        $sourceAutoload = escapeshellarg("{$vendorSourcePath}/{$appPath}/vendor/autoload.php");
        $sourceComposer = escapeshellarg("{$vendorSourcePath}/{$appPath}/vendor/composer");
        $sourceVendor = escapeshellarg("{$vendorSourcePath}/{$appPath}/vendor");
        $appVendor = "{$appPath}/vendor";

        $reuseVendor = implode(' && ', [
            "rm -rf {$appVendor}",
            "mkdir -p {$appVendor}",
            "find {$sourceVendor} -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec ln -s {} {$appPath}/vendor/ \\;",
            "cp -a {$sourceComposer} {$appPath}/vendor/composer",
            "cp {$sourceAutoload} {$appPath}/vendor/autoload.php",
            self::composerDumpAutoloadCommand($appPath),
        ]);

        return "if [ -f {$sourceAutoload} ] && [ -d {$sourceComposer} ] && [ -f {$sourceLock} ] && cmp -s {$sourceLock} {$appPath}/composer.lock; then {$reuseVendor}; {$fallbackClause}; fi";
    }

    private static function composerDumpAutoloadCommand(string $appPath): string
    {
        $localCommand = "composer --working-dir={$appPath} dump-autoload --no-interaction --optimize";

        return "if command -v composer >/dev/null 2>&1; then {$localCommand}; fi";
    }

    private static function prepareRuntimeStateCommand(?string $seedFrom, bool $dockerTopology = false, ?string $remotePath = null, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null, ?string $operationTokenSecret = null): string
    {
        $runArtisanInDockerRuntime ??= $dockerTopology;
        $runtimeDirectories = 'mkdir -p apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs';

        if ($seedFrom === null) {
            return "cp apps/gateway/.env.example apps/gateway/.env && mkdir -p apps/gateway/database && touch apps/gateway/database/database.sqlite && {$runtimeDirectories} && ".self::dockerTopologyProviderEnvCommand($dockerTopology).' && '.self::operationTokenSecretEnvCommand($operationTokenSecret).' && '.self::artisanCommand('key:generate --ansi', $runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer);
        }

        $seedEnv = escapeshellarg("{$seedFrom}/apps/gateway/.env");
        $seedDatabase = escapeshellarg("{$seedFrom}/apps/gateway/database/database.sqlite");
        $seedStorageApp = escapeshellarg("{$seedFrom}/apps/gateway/storage/app");

        return implode(' && ', [
            "if [ -f {$seedEnv} ]; then cp {$seedEnv} apps/gateway/.env; else cp apps/gateway/.env.example apps/gateway/.env; fi",
            'mkdir -p apps/gateway/database',
            "if [ -f {$seedDatabase} ]; then rm -f apps/gateway/database/database.sqlite apps/gateway/database/database.sqlite-* && cp {$seedDatabase} apps/gateway/database/database.sqlite; else touch apps/gateway/database/database.sqlite; fi",
            "if [ -d {$seedStorageApp} ]; then mkdir -p apps/gateway/storage && rm -rf apps/gateway/storage/app && cp -a {$seedStorageApp} apps/gateway/storage/app; fi",
            $runtimeDirectories,
            self::dockerTopologyProviderEnvCommand($dockerTopology),
            self::operationTokenSecretEnvCommand($operationTokenSecret),
            self::appKeyCommand($runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer),
        ]);
    }

    private static function operationTokenSecretEnvCommand(?string $operationTokenSecret): string
    {
        if ($operationTokenSecret === null) {
            return ':';
        }

        $secret = escapeshellarg($operationTokenSecret);

        return implode(' && ', [
            "grep -Ev '^(ORBIT_OPERATION_TOKEN_SECRET|ORBIT_EXECUTOR_SECRET)=' apps/gateway/.env > apps/gateway/.env.tmp || true",
            'mv apps/gateway/.env.tmp apps/gateway/.env',
            "printf 'ORBIT_OPERATION_TOKEN_SECRET=%s\\nORBIT_EXECUTOR_SECRET=%s\\n' {$secret} {$secret} >> apps/gateway/.env",
        ]);
    }

    private static function appKeyCommand(bool $dockerTopology = false, ?string $remotePath = null, ?string $dockerRuntimeContainer = null): string
    {
        return implode(' && ', [
            "(grep -q '^APP_KEY=' apps/gateway/.env || printf '%s\\n' 'APP_KEY=' >> apps/gateway/.env)",
            "(grep -Eq '^APP_KEY=base64:.+' apps/gateway/.env || ".self::artisanCommand('key:generate --force --no-interaction --ansi', $dockerTopology, $remotePath, $dockerRuntimeContainer).')',
            "grep -Eq '^APP_KEY=base64:.+' apps/gateway/.env",
        ]);
    }

    private static function dockerTopologyProviderEnvCommand(bool $dockerTopology): string
    {
        if (! $dockerTopology) {
            return ':';
        }

        return implode(' && ', [
            "grep -Ev '^(ORBIT_E2E_TOPOLOGY_PROVIDER)=' apps/gateway/.env > apps/gateway/.env.tmp",
            'mv apps/gateway/.env.tmp apps/gateway/.env',
            "printf '%s\\n' 'ORBIT_E2E_TOPOLOGY_PROVIDER=docker' >> apps/gateway/.env",
        ]);
    }

    private static function runtimeStateCommand(string $remotePath, ?string $seedFrom, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null, ?string $operationTokenSecret = null): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::prepareRuntimeStateCommand($seedFrom, $dockerTopology, $remotePath, $dockerRuntimeContainer, $runArtisanInDockerRuntime, $operationTokenSecret),
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

    private static function refreshLocalGatewaySettings(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, string $gatewayApiIp, ?E2EPhaseTimer $timer): void
    {
        self::runTimed(
            $timer,
            'checkout.gateway-settings',
            fn () => E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::localGatewaySettingsCommand($remotePath, $gatewayApiIp),
                timeoutSeconds: 120,
            ),
        );
    }

    private static function localGatewaySettingsCommand(string $remotePath, string $gatewayApiIp): string
    {
        $gatewayUrlValue = var_export("https://{$gatewayApiIp}", true);
        $gatewayApiIpValue = var_export($gatewayApiIp, true);

        $php = <<<PHP
if (\\Illuminate\\Support\\Facades\\Schema::hasTable('local_gateway_settings')) {
    \$settings = \\App\\Models\\LocalGatewaySettings::current();
    \$settings->gateway_url = {$gatewayUrlValue};
    \$settings->gateway_wg_ip = {$gatewayApiIpValue};
    \$settings->save();
}
PHP;

        // The gateway-app LocalGatewaySettings write above only configures the
        // gateway application's store. The `orbit` CLI (which the feature tests
        // invoke as the role user) needs its own ~/.config/orbit gateway entry
        // AND the gateway root CA trusted in the local OS store — otherwise CLI
        // calls fail with "Gateway URL is not configured" (no config) or
        // cURL error 60 (CA not trusted). `orbit gateway:add` fetches the CA,
        // installs it into the OS trust store, verifies node identity, and
        // persists the CLI gateway config in one step, mirroring how the docker
        // lane prepares each client node.
        return 'cd '.escapeshellarg($remotePath)
            .' && php apps/gateway/artisan tinker --execute='.escapeshellarg($php)
            .' && orbit gateway:add '.escapeshellarg($gatewayApiIp).' --json';
    }

    private static function refreshGatewayHostKeys(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?E2EPhaseTimer $timer, bool $hostLauncher = false): void
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
                    self::usesDockerRuntime($instance) && ! $hostLauncher,
                    self::usesDockerRuntime($instance) && ! $hostLauncher ? self::dockerRuntimeContainerName($instance) : null,
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
            return 'php apps/gateway/artisan '.$arguments;
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
            "find . -mindepth 1 -maxdepth 1 ! -name .env -exec sh -c 'target=\$1; shift; for path do dest=\"\$target/\$(basename \"\$path\")\"; rm -rf \"\$dest\"; cp -al \"\$path\" \"\$target\"/ 2>/dev/null || { rm -rf \"\$dest\"; cp -a --reflink=always \"\$path\" \"\$target\"/ 2>/dev/null; } || { rm -rf \"\$dest\"; cp -a \"\$path\" \"\$target\"/; }; done' sh {$quotedRemotePath} {} +",
            "rm -rf {$quotedRemotePath}/apps/gateway/vendor {$quotedRemotePath}/apps/gateway/storage {$quotedRemotePath}/apps/gateway/.env",
            "cd {$quotedRemotePath} && ".self::cloneCachedVendorCommand($basePath, $remotePath),
            "if [ -f {$quotedBasePath}/apps/gateway/.env ]; then cp {$quotedBasePath}/apps/gateway/.env {$quotedRemotePath}/apps/gateway/.env; fi",
            "mkdir -p {$quotedRemotePath}/apps/gateway/database",
            "if [ -f {$quotedBasePath}/apps/gateway/database/database.sqlite ]; then rm -f {$quotedRemotePath}/apps/gateway/database/database.sqlite {$quotedRemotePath}/apps/gateway/database/database.sqlite-* && cp {$quotedBasePath}/apps/gateway/database/database.sqlite {$quotedRemotePath}/apps/gateway/database/database.sqlite; else touch {$quotedRemotePath}/apps/gateway/database/database.sqlite; fi",
            self::cloneCachedStorageCommand($basePath, $remotePath),
        ]);
    }

    private static function cloneCachedVendorCommand(string $basePath, string $remotePath): string
    {
        return implode(' && ', [
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/gateway',
                vendorSourcePath: $basePath,
                fallbackClause: "else echo 'Cached gateway vendor dependencies are required for checkout cache clones.' >&2; exit 127",
            ),
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/cli',
                vendorSourcePath: $basePath,
                fallbackClause: "else echo 'Cached CLI vendor dependencies are required for checkout cache clones.' >&2; exit 127",
            ),
        ]);
    }

    private static function cloneCachedStorageCommand(string $basePath, string $remotePath): string
    {
        $baseStorageApp = escapeshellarg("{$basePath}/apps/gateway/storage/app");
        $remoteStorage = escapeshellarg("{$remotePath}/apps/gateway/storage");
        $remoteStorageApp = escapeshellarg("{$remotePath}/apps/gateway/storage/app");

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

        if ($topology->instance('websocket') !== null) {
            $roles[] = 'websocket';
        }

        return $roles;
    }

    /**
     * @return array{0: E2EInstance, 1: string, 2: string}
     */
    private static function topologyRoleTarget(E2ETopologyLease $topology, string $role, array $users): array
    {
        $operatorUser = $users['operator']
            ?? $users['operator']
            ?? E2EConfig::fromEnvironment()->operatorUser;

        return match ($role) {
            'operator' => [$topology->operator(), $operatorUser, "/home/{$operatorUser}/orbit"],
            'gateway' => self::requiredRole($topology->gateway(), $role, $users['gateway'] ?? 'orbit'),
            'dev' => self::requiredRole($topology->devApp(), $role, $users['dev'] ?? 'orbit'),
            'prod' => self::requiredRole($topology->prodApp(), $role, $users['prod'] ?? 'orbit'),
            'agent' => self::requiredRole($topology->agent(), $role, $users['agent'] ?? 'orbit'),
            'ingress' => self::requiredRole($topology->ingress(), $role, $users[$role] ?? 'orbit'),
            'websocket' => self::requiredRole($topology->devApp(), $role, $users[$role] ?? 'orbit'),
            default => self::requiredRole($topology->instance($role), $role, $users[$role] ?? 'orbit'),
        };
    }

    private static function topologyRoleNodeIdentity(string $role): ?string
    {
        return match ($role) {
            'operator' => 'operator-1',
            'gateway' => 'gateway',
            'dev' => 'app-dev-1',
            'prod' => 'app-prod-1',
            'agent' => 'agent-1',
            'ingress' => 'edge-1',
            'websocket' => 'app-dev-1',
            default => null,
        };
    }

    private static function topologyRoleUsesHostLauncher(string $role): bool
    {
        return in_array($role, ['operator', 'gateway', 'dev', 'prod', 'agent', 'ingress', 'websocket'], true);
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
                escapeshellarg(repo_path()),
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
            './apps/gateway/.env',
            './apps/gateway/.env.e2e',
            './apps/gateway/.env.local',
            './apps/gateway/public/build',
            './apps/gateway/public/hot',
            './apps/gateway/public/storage',
            './apps/gateway/storage/framework/e2e/*',
            './apps/gateway/storage/app/orbit/ca/*',
            './apps/gateway/storage/app/orbit/certs/*',
            './apps/gateway/storage/app/orbit/keys/*',
            './apps/gateway/storage/framework/cache/data/*',
            './apps/gateway/storage/framework/sessions/*',
            './apps/gateway/storage/framework/ssh-known-hosts/*',
            './apps/gateway/storage/framework/testing/*',
            './apps/gateway/storage/framework/views/*',
            './apps/gateway/storage/logs/*',
            './apps/gateway/storage/pail',
            './apps/gateway/tests/E2E/.docker-feature-tests/*',
            './apps/gateway/tests/E2E/.incus-feature-tests/*',
            './apps/gateway/vendor',
            './apps/cli/vendor',
            './apps/docs/vendor',
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
            $absolutePath = repo_path($path);
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
        $output = (string) shell_exec('git -C '.escapeshellarg(repo_path()).' ls-files -z --full-name --cached --others --exclude-standard 2>/dev/null');
        $paths = array_values(array_filter(explode("\0", $output), fn (string $path): bool => $path !== ''));

        $paths = array_values(array_filter($paths, self::shouldIncludeArchivePath(...)));
        $paths = array_values(array_unique($paths));

        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function shouldIncludeArchivePath(string $path): bool
    {
        $path = self::normalizeArchivePath($path);

        if ($path === '' || ! is_file(repo_path($path))) {
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
