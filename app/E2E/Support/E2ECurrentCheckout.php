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
            );
        }

        return $paths;
    }

    public static function install(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom = null, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null): string
    {
        if (self::checkoutCacheEnabled()) {
            return self::installFromCachedBase($instance, $user, $keyPair, $seedFrom, $timer, $afterBaseInstall, $afterInstall);
        }

        $remotePath = "/home/{$user}/orbit-current";
        $tarball = $timer?->measure('checkout.archive', fn (): string => self::buildArchive()) ?? self::buildArchive();

        try {
            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));
            self::runInstallPhases($instance, $user, $keyPair, $remotePath, $seedFrom, $timer);
            self::activateCurrentCheckout($instance, $remotePath);
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

    public static function orbitWrapperScript(string $checkout, bool $dockerRuntime): string
    {
        if ($dockerRuntime) {
            return implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
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
                '    php '.escapeshellarg("{$checkout}/artisan").' "$@"',
                '',
            ]);
        }

        $php = 'p'.'hp';

        return "#!/usr/bin/env bash\nset -euo pipefail\nexec {$php} ".escapeshellarg("{$checkout}/artisan").' "$@"'."\n";
    }

    private static function checkoutCacheEnabled(): bool
    {
        $value = getenv('ORBIT_E2E_CHECKOUT_CACHE');

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'yes', 'process'], true);
    }

    private static function installFromCachedBase(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null): string
    {
        $cacheKey = implode('|', [$instance->name(), $user, $seedFrom ?? '']);
        $basePath = "/home/{$user}/orbit-current-base-".substr(sha1($cacheKey), 0, 10);
        $reusedBase = isset(self::$cachedBasePaths[$cacheKey]);

        if (! $reusedBase) {
            $tarball = self::cachedArchive($timer);

            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));

            self::runInstallPhases($instance, $user, $keyPair, $basePath, $seedFrom, $timer);
            self::activateCurrentCheckout($instance, $basePath);
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

        self::activateCurrentCheckout($instance, $remotePath);
        $afterInstall?->__invoke($remotePath);

        return $remotePath;
    }

    private static function activateCurrentCheckout(E2EInstance $instance, string $remotePath): void
    {
        if (! self::usesDockerRuntime($instance)) {
            return;
        }

        $tmpScript = tempnam(sys_get_temp_dir(), 'orbit-current-');

        if (! is_string($tmpScript)) {
            throw new RuntimeException('Could not create temporary current-checkout orbit wrapper.');
        }

        try {
            if (file_put_contents($tmpScript, self::orbitWrapperScript($remotePath, dockerRuntime: true)) === false) {
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

    private static function runInstallPhases(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?string $seedFrom, ?E2EPhaseTimer $timer): void
    {
        $vendorSourcePath = $seedFrom ?? "/home/{$user}/orbit";
        $deadline = self::now() + 600.0;
        $dockerTopology = self::usesDockerRuntime($instance);

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
            fn (): string => self::vendorInstallCommand($remotePath, $vendorSourcePath, $dockerTopology),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.runtime-state',
            fn (): string => self::runtimeStateCommand($remotePath, $seedFrom, $dockerTopology),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.migrate',
            fn (): string => self::migrateCommand($remotePath, $dockerTopology),
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
            'rm -rf '.escapeshellarg($remotePath),
            'mkdir -p '.escapeshellarg($remotePath),
            'tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C '.escapeshellarg($remotePath),
            'sudo rm -f /tmp/orbit-current.tar.gz',
        ]);
    }

    private static function vendorInstallCommand(string $remotePath, string $vendorSourcePath, bool $dockerTopology = false): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            $dockerTopology
                ? self::reuseRuntimeDependenciesCommand($vendorSourcePath)
                : self::installComposerDependenciesCommand($vendorSourcePath),
        ]);
    }

    private static function reuseRuntimeDependenciesCommand(string $vendorSourcePath): string
    {
        $sourceVendor = escapeshellarg("{$vendorSourcePath}/vendor");

        return "test -d {$sourceVendor} && rm -rf vendor && ln -s {$sourceVendor} vendor";
    }

    private static function installComposerDependenciesCommand(string $vendorSourcePath): string
    {
        $sourceLock = escapeshellarg("{$vendorSourcePath}/composer.lock");
        $sourceAutoload = escapeshellarg("{$vendorSourcePath}/vendor/autoload.php");
        $sourceBoost = escapeshellarg("{$vendorSourcePath}/vendor/laravel/boost");
        $sourceComposer = escapeshellarg("{$vendorSourcePath}/vendor/composer");
        $sourceVendor = escapeshellarg("{$vendorSourcePath}/vendor");
        $reuseVendor = implode(' && ', [
            'rm -rf vendor',
            'mkdir -p vendor',
            "find {$sourceVendor} -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec ln -s {} vendor/ \\;",
            "cp -a {$sourceComposer} vendor/composer",
            "cp {$sourceAutoload} vendor/autoload.php",
            'composer dump-autoload --no-interaction --optimize',
        ]);

        return "if [ -f {$sourceAutoload} ] && [ -d {$sourceBoost} ] && [ -d {$sourceComposer} ] && [ -f {$sourceLock} ] && cmp -s {$sourceLock} composer.lock; then {$reuseVendor}; else composer install --no-interaction --prefer-dist --optimize-autoloader; fi";
    }

    private static function prepareRuntimeStateCommand(?string $seedFrom, bool $dockerTopology = false, ?E2EPhaseTimer $timer = null): string
    {
        $runtimeDirectories = 'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs';

        if ($seedFrom === null) {
            return "cp .env.example .env && mkdir -p database && touch database/database.sqlite && {$runtimeDirectories} && ".self::artisanCommand('key:generate --ansi', $dockerTopology);
        }

        $seedEnv = escapeshellarg("{$seedFrom}/.env");
        $seedDatabase = escapeshellarg("{$seedFrom}/database/database.sqlite");
        $seedStorageApp = escapeshellarg("{$seedFrom}/storage/app");

        return implode(' && ', [
            "if [ -f {$seedEnv} ]; then cp {$seedEnv} .env; else cp .env.example .env; fi",
            'mkdir -p database',
            "if [ -f {$seedDatabase} ]; then cp {$seedDatabase} database/database.sqlite; else touch database/database.sqlite; fi",
            "if [ -d {$seedStorageApp} ]; then mkdir -p storage && rm -rf storage/app && cp -a {$seedStorageApp} storage/app; fi",
            $runtimeDirectories,
            self::dockerTopologyModeEnvCommand(),
            self::appKeyCommand($dockerTopology),
        ]);
    }

    private static function appKeyCommand(bool $dockerTopology = false): string
    {
        return implode(' && ', [
            "(grep -q '^APP_KEY=' .env || printf '%s\\n' 'APP_KEY=' >> .env)",
            "(grep -Eq '^APP_KEY=base64:.+' .env || ".self::artisanCommand('key:generate --force --no-interaction --ansi', $dockerTopology).')',
            "grep -Eq '^APP_KEY=base64:.+' .env",
        ]);
    }

    private static function dockerTopologyModeEnvCommand(): string
    {
        if (getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE') !== 'dns-alias') {
            return ':';
        }

        return implode(' && ', [
            "grep -Ev '^(ORBIT_E2E_DOCKER_TOPOLOGY_MODE)=' .env > .env.tmp",
            'mv .env.tmp .env',
            "printf '%s\\n' 'ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias' >> .env",
        ]);
    }

    private static function runtimeStateCommand(string $remotePath, ?string $seedFrom, bool $dockerTopology = false): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::prepareRuntimeStateCommand($seedFrom, $dockerTopology),
        ]);
    }

    private static function migrateCommand(string $remotePath, bool $dockerTopology = false): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::artisanCommand('migrate --force --ansi', $dockerTopology),
        ]);
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
                self::hostKeyRefreshCommand($remotePath, self::usesDockerRuntime($instance)),
                timeoutSeconds: 120,
            ),
        );
    }

    private static function hostKeyRefreshCommand(string $remotePath, bool $dockerTopology = false): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::artisanCommand('orbit:internal:pin-node-host-keys --json', $dockerTopology),
        ]);
    }

    private static function artisanCommand(string $arguments, bool $dockerTopology): string
    {
        return ($dockerTopology ? 'orbit' : 'php artisan').' '.$arguments;
    }

    private static function usesDockerRuntime(E2EInstance $instance): bool
    {
        return $instance instanceof DockerInstance || $instance instanceof DockerBuildInstance;
    }

    private static function cloneCachedCheckoutCommand(string $basePath, string $remotePath): string
    {
        $quotedBasePath = escapeshellarg($basePath);
        $quotedRemotePath = escapeshellarg($remotePath);

        return implode(' && ', [
            "rm -rf {$quotedRemotePath}",
            "mkdir -p {$quotedRemotePath}",
            "cd {$quotedBasePath}",
            "find . -mindepth 1 -maxdepth 1 ! -name vendor ! -name database ! -name storage ! -name .env -exec sh -c 'target=\$1; shift; for path do cp -al \"\$path\" \"\$target\"/ 2>/dev/null || cp -a --reflink=always \"\$path\" \"\$target\"/ 2>/dev/null || cp -a \"\$path\" \"\$target\"/; done' sh {$quotedRemotePath} {} +",
            self::cloneCachedVendorCommand($basePath, $remotePath),
            "cp {$quotedBasePath}/.env {$quotedRemotePath}/.env",
            "mkdir -p {$quotedRemotePath}/database",
            "if [ -f {$quotedBasePath}/database/database.sqlite ]; then cp {$quotedBasePath}/database/database.sqlite {$quotedRemotePath}/database/database.sqlite; else touch {$quotedRemotePath}/database/database.sqlite; fi",
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
            "if [ -d {$baseStorageApp} ]; then cp -a {$baseStorageApp} {$remoteStorageApp}; fi",
        ]);
    }

    /**
     * @return list<string>
     */
    private static function availableTopologyRoles(E2ETopologyLease $topology): array
    {
        $roles = ['operator', 'control'];

        if ($topology->gateway() !== null) {
            $roles[] = 'gateway';
        }

        if ($topology->devApp() !== null) {
            $roles[] = 'dev';
        }

        if ($topology->prodApp() !== null) {
            $roles[] = 'prod';
        }

        if ($topology->agent() !== null) {
            $roles[] = 'agent';
        }

        if ($topology->ingress() !== null) {
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
            'ingress', 'ingress' => self::requiredRole($topology->ingress(), $role, $users[$role] ?? 'orbit'),
            default => throw new RuntimeException("Unknown topology role [{$role}]."),
        };
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
        $manifest = self::writeArchiveManifest();

        try {
            $result = Process::timeout(300)->run(sprintf(
                'COPYFILE_DISABLE=1 tar --null -czf %s -C %s -T %s',
                escapeshellarg($tarball),
                escapeshellarg(base_path()),
                escapeshellarg($manifest),
            ));

            if (! $result->successful()) {
                throw new RuntimeException("Failed to build current checkout archive: {$result->errorOutput()}");
            }
        } finally {
            @unlink($manifest);
        }

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
            './database/*.sqlite',
            './database/*.sqlite-*',
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
            './tests/E2E/.docker-feature-tests/*',
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
