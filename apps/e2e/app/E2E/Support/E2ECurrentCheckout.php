<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class E2ECurrentCheckout
{
    private const string SourceMountedGuestPath = '/home/orbit/orbit';

    private const string GatewayArtisanRelativePath = 'apps/gateway/artisan';

    private const string OrbitConfigRoot = '/home/orbit/.config/orbit';

    private static ?string $cachedArchive = null;

    private static bool $cachedArchiveIsShared = false;

    /** @var array<string, string> */
    private static array $cachedBasePaths = [];

    /** @var (\Closure(): float)|null */
    private static ?\Closure $nowResolver = null;

    /** @var (\Closure(string, E2EPhaseTimer): string)|null */
    private static ?\Closure $installerForTests = null;

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

        if (self::canInstallTopologyRolesConcurrently($topology, $roles, $users)) {
            return self::installTopologyRolesConcurrently($topology, $roles, $users, $timer);
        }

        $paths = [];

        foreach ($roles as $role) {
            $paths[$role] = self::installTopologyRole($topology, $role, $users, $timer?->child($role));
        }

        return $paths;
    }

    /**
     * @param  array<string, string>  $users
     */
    private static function installTopologyRole(E2ETopologyLease $topology, string $role, array $users, ?E2EPhaseTimer $roleTimer): string
    {
        if (self::$installerForTests !== null) {
            return (self::$installerForTests)($role, $roleTimer ?? new E2EPhaseTimer);
        }

        [$instance, $user, $seedFrom] = self::topologyRoleTarget($topology, $role, $users);
        $executorNodeIdentity = self::topologyRoleNodeIdentity($role);
        $hostLauncher = self::topologyRoleUsesHostLauncher($role);
        $refreshGatewayHostKeys = $role === 'gateway'
            ? function (string $remotePath, bool $sourceMountedCheckout = false) use ($instance, $user, $topology, $roleTimer, $hostLauncher): void {
                self::refreshGatewayHostKeys($instance, $user, $topology->sshKeyPair(), $remotePath, $roleTimer, $hostLauncher, $sourceMountedCheckout);
            }
        : null;
        // Configure the orbit CLI gateway endpoint on every node that has a
        // gateway, INCLUDING the gateway node itself: gateway-role feature
        // tests run `orbit` against the gateway API as a self-call to the
        // gateway's own WireGuard IP (routed locally), so the gateway's CLI
        // needs the same ~/.config/orbit gateway entry + CA trust as clients.
        $refreshLocalGatewaySettings = $topology->gateway() !== null && ! self::usesDockerRuntime($instance)
            ? function (string $remotePath, bool $sourceMountedCheckout = false) use ($instance, $user, $topology, $roleTimer): void {
                self::refreshLocalGatewaySettings($instance, $user, $topology->sshKeyPair(), $remotePath, $topology->gatewayApiIp(), $roleTimer, $sourceMountedCheckout);
            }
        : null;
        $afterInstall = ($refreshGatewayHostKeys !== null || $refreshLocalGatewaySettings !== null)
            ? function (string $remotePath, bool $sourceMountedCheckout = false) use ($refreshGatewayHostKeys, $refreshLocalGatewaySettings): void {
                $refreshGatewayHostKeys?->__invoke($remotePath, $sourceMountedCheckout);
                $refreshLocalGatewaySettings?->__invoke($remotePath, $sourceMountedCheckout);
            }
        : null;

        return self::install(
            $instance,
            $user,
            $topology->sshKeyPair(),
            seedFrom: $seedFrom,
            timer: $roleTimer,
            afterBaseInstall: $refreshGatewayHostKeys,
            afterInstall: $afterInstall,
            executorNodeIdentity: $executorNodeIdentity,
            hostLauncher: $hostLauncher,
        );
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, string>  $users
     */
    private static function canInstallTopologyRolesConcurrently(E2ETopologyLease $topology, array $roles, array $users): bool
    {
        if (count($roles) < 2) {
            return false;
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            return false;
        }

        foreach ($roles as $role) {
            [$instance, $user] = self::topologyRoleTarget($topology, $role, $users);
            $hostLauncher = self::topologyRoleUsesHostLauncher($role);

            if (self::usesDockerRuntime($instance)) {
                return false;
            }

            if (self::$installerForTests === null && ! $instance instanceof IncusInstance) {
                return false;
            }

            if (self::sourceMountedCheckoutPath($instance, $user, $hostLauncher) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, string>  $users
     * @return array<string, string>
     */
    private static function installTopologyRolesConcurrently(E2ETopologyLease $topology, array $roles, array $users, ?E2EPhaseTimer $timer): array
    {
        $workers = [];

        foreach ($roles as $role) {
            $resultPath = tempnam(sys_get_temp_dir(), 'orbit-checkout-role-');

            if (! is_string($resultPath)) {
                throw new RuntimeException('Could not create temporary checkout role result path.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                @unlink($resultPath);

                return self::installTopologyRolesSequentially($topology, $roles, $users, $timer);
            }

            if ($pid === 0) {
                $exitCode = self::runTopologyRoleInstallWorker($topology, $role, $users, $timer, $resultPath);

                if (function_exists('posix_kill')) {
                    posix_kill(getmypid(), SIGKILL);
                }

                exit($exitCode);
            }

            $workers[$role] = [
                'pid' => $pid,
                'result_path' => $resultPath,
            ];
        }

        return self::collectTopologyRoleInstallWorkers($workers, $timer);
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, string>  $users
     * @return array<string, string>
     */
    private static function installTopologyRolesSequentially(E2ETopologyLease $topology, array $roles, array $users, ?E2EPhaseTimer $timer): array
    {
        $paths = [];

        foreach ($roles as $role) {
            $paths[$role] = self::installTopologyRole($topology, $role, $users, $timer?->child($role));
        }

        return $paths;
    }

    /**
     * @param  array<string, string>  $users
     */
    private static function runTopologyRoleInstallWorker(E2ETopologyLease $topology, string $role, array $users, ?E2EPhaseTimer $timer, string $resultPath): int
    {
        $roleTimer = $timer?->child($role);

        try {
            $path = self::installTopologyRole($topology, $role, $users, $roleTimer);

            file_put_contents($resultPath, serialize([
                'path' => $path,
                'events' => $roleTimer?->events() ?? [],
            ]));

            return 0;
        } catch (\Throwable $throwable) {
            file_put_contents($resultPath, serialize([
                'error' => [
                    'type' => get_debug_type($throwable),
                    'message' => $throwable->getMessage(),
                ],
                'events' => $roleTimer?->events() ?? [],
            ]));

            return 1;
        }
    }

    /**
     * @param  array<string, array{pid: int, result_path: string}>  $workers
     * @return array<string, string>
     */
    private static function collectTopologyRoleInstallWorkers(array $workers, ?E2EPhaseTimer $timer): array
    {
        $paths = [];
        $failures = [];

        foreach ($workers as $role => $worker) {
            pcntl_waitpid($worker['pid'], $status);

            try {
                $payload = self::readTopologyRoleWorkerPayload($worker['result_path']);
                $timer?->mergeExternalEvents($payload['events'] ?? []);

                if (isset($payload['error']) || ! isset($payload['path'])) {
                    $error = $payload['error'] ?? ['type' => 'worker', 'message' => 'exited without a result'];
                    $failures[] = "{$role}: {$error['type']}: {$error['message']}";

                    continue;
                }

                $paths[$role] = $payload['path'];
            } finally {
                @unlink($worker['result_path']);
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('Could not install current checkout roles in parallel: '.implode('; ', $failures));
        }

        return $paths;
    }

    /**
     * @return array{path?: string, events?: list<array{name: string, seconds: float}>, error?: array{type: string, message: string}}
     */
    private static function readTopologyRoleWorkerPayload(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents) || $contents === '') {
            return [
                'error' => [
                    'type' => 'worker',
                    'message' => 'did not write a result',
                ],
                'events' => [],
            ];
        }

        $payload = unserialize($contents, ['allowed_classes' => false]);

        if (! is_array($payload)) {
            return [
                'error' => [
                    'type' => 'worker',
                    'message' => 'wrote an invalid result',
                ],
                'events' => [],
            ];
        }

        return $payload;
    }

    public static function install(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom = null, ?E2EPhaseTimer $timer = null, ?\Closure $afterBaseInstall = null, ?\Closure $afterInstall = null, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
    {
        $sourceMountedCheckoutPath = self::sourceMountedCheckoutPath($instance, $user, $hostLauncher);

        if ($sourceMountedCheckoutPath !== null) {
            if (self::usesDockerRuntime($instance)) {
                $remotePath = $sourceMountedCheckoutPath;

                self::runInstallPhases($instance, $user, $keyPair, $remotePath, $seedFrom, $timer, $hostLauncher, sourceMountedCheckout: true);
                self::activateCurrentCheckout($instance, $remotePath, $executorNodeIdentity, $hostLauncher);
                $afterInstall?->__invoke($remotePath, true);

                return $remotePath;
            }

            $remotePath = self::sourceMountedRuntimePath($user);

            self::refreshSourceMountedRuntimeOverlay($instance, $user, $keyPair, $sourceMountedCheckoutPath, $remotePath, $timer);

            self::runInstallPhases($instance, $user, $keyPair, $remotePath, $sourceMountedCheckoutPath, $timer, $hostLauncher, sourceMountedCheckout: true, checkoutAlreadyPresent: true);
            self::activateCurrentCheckout($instance, $remotePath, $executorNodeIdentity, $hostLauncher);
            $afterInstall?->__invoke($remotePath, true);

            return $remotePath;
        }

        if (self::checkoutCacheEnabled()) {
            return self::installFromCachedBase($instance, $user, $keyPair, $seedFrom, $timer, $afterBaseInstall, $afterInstall, $executorNodeIdentity, $hostLauncher);
        }

        $remotePath = "/home/{$user}/orbit-current";
        $tarball = $timer?->measure('checkout.archive', fn (): string => self::buildArchive()) ?? self::buildArchive();

        try {
            self::runTimed($timer, 'checkout.copy', fn (): null => self::copyArchive($tarball, $instance));
            self::runInstallPhases($instance, $user, $keyPair, $remotePath, $seedFrom, $timer, $hostLauncher);
            self::activateCurrentCheckout($instance, $remotePath, $executorNodeIdentity, $hostLauncher);
            $afterInstall?->__invoke($remotePath, false);

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

    public static function sourceMountedGuestPath(): string
    {
        return self::SourceMountedGuestPath;
    }

    public static function sourceMountedRuntimePath(string $user): string
    {
        return "/home/{$user}/orbit-run";
    }

    public static function sourceMountedRuntimeOverlayCommand(string $sourcePath, string $targetPath, bool $resetUpper = true): string
    {
        $targetPath = rtrim($targetPath, '/');
        $overlayBase = dirname($targetPath).'/.'.basename($targetPath).'-overlay';
        $resetCommand = $resetUpper ? '$sudo_prefix rm -rf "$target" "$upper" "$work"' : ': preserve overlay upperdir';

        return implode("\n", [
            'source='.escapeshellarg(rtrim($sourcePath, '/')),
            'target='.escapeshellarg($targetPath),
            'upper='.escapeshellarg("{$overlayBase}/upper"),
            'work='.escapeshellarg("{$overlayBase}/work"),
            'sudo_prefix=',
            'if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then sudo_prefix=sudo; fi',
            'if ! grep -qw overlay /proc/filesystems; then echo "overlayfs is not available on this node." >&2; exit 127; fi',
            'if mountpoint -q "$target"; then $sudo_prefix umount "$target"; fi',
            $resetCommand,
            'mkdir -p "$target" "$upper" "$work"',
            'if [ -n "$sudo_prefix" ]; then $sudo_prefix chown -R "$(id -u):$(id -g)" "$target" "$upper" "$work"; fi',
            '$sudo_prefix mount -t overlay overlay -o "lowerdir=$source,upperdir=$upper,workdir=$work" "$target"',
        ]);
    }

    private static function refreshSourceMountedRuntimeOverlay(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $sourcePath, string $targetPath, ?E2EPhaseTimer $timer): void
    {
        self::runTimed(
            $timer,
            'checkout.source-overlay',
            fn () => E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::sourceMountedRuntimeOverlayCommand($sourcePath, $targetPath),
                timeoutSeconds: 120,
            ),
        );
    }

    public static function useNowResolverForTests(?callable $resolver): void
    {
        self::$nowResolver = $resolver !== null ? \Closure::fromCallable($resolver) : null;
    }

    public static function useInstallerForTests(?callable $installer): void
    {
        self::$installerForTests = $installer !== null ? \Closure::fromCallable($installer) : null;
    }

    public static function orbitWrapperScript(string $checkout, bool $dockerRuntime, ?string $executorNodeIdentity = null, bool $hostLauncher = false): string
    {
        if ($hostLauncher) {
            return implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'checkout='.escapeshellarg($checkout),
                'export ORBIT_REPO="${checkout}"',
                'export ORBIT_HOST_CWD="${ORBIT_HOST_CWD:-$PWD}"',
                'exec "${checkout}/bin/orbit" "$@"',
                '',
            ]);
        }

        if ($dockerRuntime) {
            return implode("\n", [
                '#!/usr/bin/env bash',
                'set -euo pipefail',
                'checkout='.escapeshellarg($checkout),
                'runtime_container="${ORBIT_GATEWAY_CONTAINER:-orbit-gateway}"',
                'runtime_workdir="${ORBIT_HOST_CWD:-$PWD}"',
                'env_args=(',
                '    --env "ORBIT_HOST_CWD=${runtime_workdir}"',
                '    --env '.escapeshellarg("ORBIT_SOURCE_PATH={$checkout}"),
                '    --env '.escapeshellarg('ORBIT_CONFIG_ROOT='.self::OrbitConfigRoot),
                '    --env '.escapeshellarg('DB_CONNECTION=sqlite'),
                '    --env '.escapeshellarg('DB_DATABASE='.self::OrbitConfigRoot.'/gateway.sqlite'),
                '    --env '.escapeshellarg('SESSION_DRIVER=file'),
                ')',
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
            $afterBaseInstall?->__invoke($basePath, false);

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
        $afterInstall?->__invoke($remotePath, false);

        return $remotePath;
    }

    private static function activateCurrentCheckout(E2EInstance $instance, string $remotePath, ?string $executorNodeIdentity = null, bool $hostLauncher = false): void
    {
        if ($hostLauncher) {
            E2ECommand::exec(
                $instance,
                self::hostLauncherActivationCommand($remotePath),
                'Could not prepare the source-mounted host launcher.',
                timeoutSeconds: 60,
            );

            return;
        }

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

    private static function runInstallPhases(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?string $seedFrom, ?E2EPhaseTimer $timer, bool $hostLauncher = false, bool $sourceMountedCheckout = false, bool $checkoutAlreadyPresent = false): void
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
            fn (): string => self::extractCheckoutCommand($remotePath, $sourceMountedCheckout || $checkoutAlreadyPresent),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::copyIncusVendorArchives($instance, $remotePath, $timer);

        self::runInstallPhase(
            $timer,
            'checkout.vendor',
            fn (): string => self::vendorInstallCommand($remotePath, $vendorSourcePath, $dockerTopology, $vendorRuntimeContainer, $requiresGatewayApplication, $sourceMountedCheckout),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.runtime-state',
            fn (): string => self::runtimeStateCommand($remotePath, $seedFrom, $dockerTopology, $dockerRuntimeContainer, $runBootstrapInDockerRuntime, $sourceMountedCheckout),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );

        self::runInstallPhase(
            $timer,
            'checkout.migrate',
            fn (): string => self::migrateCommand($remotePath, $dockerTopology, $dockerRuntimeContainer, $runBootstrapInDockerRuntime, $sourceMountedCheckout),
            $instance,
            $user,
            $keyPair,
            $deadline,
        );
    }

    private static function copyIncusVendorArchives(E2EInstance $instance, string $remotePath, ?E2EPhaseTimer $timer): void
    {
        if (! $instance instanceof IncusInstance) {
            return;
        }

        self::runTimed($timer, 'checkout.vendor-archives', function () use ($instance, $remotePath): void {
            E2ECommand::exec(
                $instance,
                'mkdir -p '.escapeshellarg("{$remotePath}/".SourceMountedCheckoutSyncer::VendorArchiveDirectory),
                'Could not prepare current checkout vendor archive directory.',
                timeoutSeconds: 30,
            );

            foreach (['apps/gateway', 'apps/cli'] as $appPath) {
                $relativeArchive = SourceMountedCheckoutSyncer::vendorArchiveRelativePath($appPath);

                $instance->copyHostFileToInstance(
                    $instance->hostSourcePath().'/'.$relativeArchive,
                    "{$remotePath}/{$relativeArchive}",
                );
            }
        });
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

    private static function extractCheckoutCommand(string $remotePath, bool $sourceMounted = false): string
    {
        if ($sourceMounted) {
            return 'mkdir -p '.escapeshellarg($remotePath);
        }

        return implode(' && ', [
            'sudo rm -rf '.escapeshellarg($remotePath),
            'mkdir -p '.escapeshellarg($remotePath),
            'tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C '.escapeshellarg($remotePath),
            'sudo rm -f /tmp/orbit-current.tar.gz',
        ]);
    }

    private static function vendorInstallCommand(string $remotePath, string $vendorSourcePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, bool $requiresGatewayApplication = true, bool $sourceMountedCheckout = false): string
    {
        if ($sourceMountedCheckout) {
            return implode(' && ', [
                'cd '.escapeshellarg($remotePath),
                self::verifyOrInstallVendorArchiveCommand('apps/gateway', 'Gateway'),
                self::verifyOrInstallVendorArchiveCommand('apps/cli', 'CLI'),
            ]);
        }

        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            $dockerTopology
                ? self::reuseRuntimeDependenciesCommand($vendorSourcePath, $remotePath, $dockerRuntimeContainer, $requiresGatewayApplication)
                : self::installComposerDependenciesCommand($vendorSourcePath),
        ]);
    }

    private static function reuseRuntimeDependenciesCommand(string $vendorSourcePath, string $remotePath, ?string $dockerRuntimeContainer = null, bool $requiresGatewayApplication = true): string
    {
        if ($vendorSourcePath === $remotePath) {
            return implode(' && ', [
                self::verifyMountedVendorAutoloadCommand('apps/gateway', 'Gateway'),
                self::verifyMountedVendorAutoloadCommand('apps/cli', 'CLI'),
            ]);
        }

        $gatewayFallback = $requiresGatewayApplication
            ? self::runtimeComposerInstallCommand($remotePath, $dockerRuntimeContainer)
            : "echo 'Prepared gateway vendor dependencies are required for Docker host-launcher checkout.' >&2; exit 127";

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
        $commands[] = self::copyCliEnvCommand($vendorSourcePath);

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

        $environmentFlags = implode(' ', [
            ...array_map(
                fn (string $value): string => '--env '.escapeshellarg($value),
                $environment,
            ),
            ...E2EGitHubAuth::dockerEnvOptions(),
        ]);

        $composerConfig = sprintf(
            'mkdir -p %s %s && printf %%s %s > %s && %s',
            escapeshellarg('/tmp/orbit-composer-cache'),
            escapeshellarg('/tmp/orbit-composer-home'),
            escapeshellarg(json_encode([
                'config' => [
                    'cache-dir' => '/tmp/orbit-composer-cache',
                    'github-protocols' => ['https'],
                ],
            ], JSON_THROW_ON_ERROR)),
            escapeshellarg('/tmp/orbit-composer-home/config.json'),
            E2EGitHubAuth::composerAuthConfigCommand('/tmp/orbit-composer-home'),
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
                fallbackClause: self::vendorArchiveFallbackClause(
                    appPath: 'apps/gateway',
                    fallbackClause: "elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/gateway install --no-interaction --prefer-dist --optimize-autoloader; else echo 'Gateway Composer dependencies are not installed and prepared vendor dependencies could not be reused.' >&2; exit 127",
                ),
            ),
            self::reusePreparedVendorWithLocalAutoloadCommand(
                appPath: 'apps/cli',
                vendorSourcePath: $vendorSourcePath,
                fallbackClause: self::vendorArchiveFallbackClause(
                    appPath: 'apps/cli',
                    fallbackClause: "elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/cli install --no-interaction --prefer-dist --optimize-autoloader; else echo 'CLI Composer dependencies are not installed and prepared vendor dependencies could not be reused.' >&2; exit 127",
                ),
            ),
        ]);
    }

    private static function vendorArchiveFallbackClause(string $appPath, string $fallbackClause): string
    {
        $archive = escapeshellarg(SourceMountedCheckoutSyncer::vendorArchiveRelativePath($appPath));
        $appVendor = escapeshellarg("{$appPath}/vendor");
        $appPathArgument = escapeshellarg($appPath);

        return implode(' ', [
            "elif [ -f {$archive} ]; then",
            "rm -rf {$appVendor};",
            "tar --warning=no-unknown-keyword -C {$appPathArgument} -xf {$archive};",
            self::composerDumpAutoloadCommand($appPath).';',
            $fallbackClause,
        ]);
    }

    private static function verifyOrInstallVendorArchiveCommand(string $appPath, string $label): string
    {
        $archive = escapeshellarg(SourceMountedCheckoutSyncer::vendorArchiveRelativePath($appPath));
        $autoloadPath = escapeshellarg("{$appPath}/vendor/autoload.php");
        $appVendor = escapeshellarg("{$appPath}/vendor");
        $appPathArgument = escapeshellarg($appPath);

        return implode(' ', [
            "if [ -f {$autoloadPath} ]; then",
            ':;',
            "elif [ -f {$archive} ]; then",
            "rm -rf {$appVendor};",
            "tar --warning=no-unknown-keyword -C {$appPathArgument} -xf {$archive};",
            self::composerDumpAutoloadCommand($appPath).';',
            "else echo '{$label} Composer dependencies are not installed and prepared vendor archive {$archive} is missing.' >&2; exit 127;",
            'fi',
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
        $appVendorArgument = escapeshellarg($appVendor);

        $reuseVendor = implode(' && ', [
            "rm -rf {$appVendorArgument}",
            "mkdir -p {$appVendorArgument}",
            "find {$sourceVendor} -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec sh -c 'target=\$1; shift; for path do dest=\"\$target/\$(basename \"\$path\")\"; rm -rf \"\$dest\"; cp -al \"\$path\" \"\$target\"/ 2>/dev/null || { rm -rf \"\$dest\"; cp -a --reflink=always \"\$path\" \"\$target\"/ 2>/dev/null; } || { rm -rf \"\$dest\"; cp -a \"\$path\" \"\$target\"/; }; done' sh {$appVendorArgument} {} +",
            "cp -a {$sourceComposer} {$appVendorArgument}/composer",
            "cp {$sourceAutoload} {$appVendorArgument}/autoload.php",
            self::composerDumpAutoloadCommand($appPath),
        ]);

        return "if [ -f {$sourceAutoload} ] && [ -d {$sourceComposer} ] && [ -f {$sourceLock} ] && cmp -s {$sourceLock} {$appPath}/composer.lock; then {$reuseVendor}; {$fallbackClause}; fi";
    }

    private static function composerDumpAutoloadCommand(string $appPath): string
    {
        $localCommand = "composer --working-dir={$appPath} dump-autoload --no-interaction --optimize";

        return "if command -v composer >/dev/null 2>&1; then {$localCommand}; fi";
    }

    private static function verifyMountedVendorAutoloadCommand(string $appPath, string $label): string
    {
        $autoloadPath = escapeshellarg("{$appPath}/vendor/autoload.php");

        return "if [ ! -f {$autoloadPath} ]; then echo '{$label} Composer dependencies must already exist in the mounted source tree at {$appPath}/vendor/autoload.php.' >&2; exit 127; fi";
    }

    private static function copyCliEnvCommand(string $vendorSourcePath): string
    {
        $sourceCliEnv = escapeshellarg("{$vendorSourcePath}/apps/cli/.env");

        return "if [ -f {$sourceCliEnv} ]; then cp {$sourceCliEnv} apps/cli/.env; fi";
    }

    private static function prepareRuntimeStateCommand(?string $seedFrom, bool $dockerTopology = false, ?string $remotePath = null, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null, bool $sourceMountedCheckout = false): string
    {
        $runArtisanInDockerRuntime ??= $dockerTopology;
        $runtimeDirectories = self::dockerGatewayStateBootstrapCommand();

        if ($sourceMountedCheckout) {
            return implode(' && ', [
                $runtimeDirectories,
                self::appKeyCommand(sourceMountedCheckout: true, remotePath: $remotePath),
            ]);
        }

        if ($dockerTopology) {
            return implode(' && ', [
                $runtimeDirectories,
                self::dockerTopologyProviderEnvCommand(true),
                self::appKeyCommand(dockerTopology: true, runArtisanInDockerRuntime: $runArtisanInDockerRuntime, remotePath: $remotePath, dockerRuntimeContainer: $dockerRuntimeContainer),
            ]);
        }

        return implode(' && ', [
            $runtimeDirectories,
            self::dockerTopologyProviderEnvCommand(false),
            self::appKeyCommand(runArtisanInDockerRuntime: $runArtisanInDockerRuntime, remotePath: $remotePath, dockerRuntimeContainer: $dockerRuntimeContainer),
        ]);
    }

    private static function dockerGatewayStateBootstrapCommand(): string
    {
        $configRoot = escapeshellarg(self::OrbitConfigRoot);
        $gatewayEnv = escapeshellarg(self::gatewayStatePath('.env'));
        $gatewayEnvTmp = escapeshellarg(self::gatewayStatePath('.env.tmp'));
        $gatewayDatabase = escapeshellarg(self::gatewayStatePath('gateway.sqlite'));

        return implode(' && ', [
            "if command -v sudo >/dev/null 2>&1; then sudo install -d -m 775 -o orbit -g orbit {$configRoot} && sudo chown -R orbit:orbit {$configRoot}; else install -d -m 775 {$configRoot}; fi",
            "mkdir -p {$configRoot} apps/gateway/storage/framework/cache/data apps/gateway/storage/framework/sessions apps/gateway/storage/framework/testing apps/gateway/storage/framework/views apps/gateway/storage/logs",
            "if [ ! -f {$gatewayEnv} ]; then cp apps/gateway/.env.example {$gatewayEnv}; fi",
            "rm -f {$gatewayEnvTmp}",
            "grep -Ev '^(DB_DATABASE|SESSION_DRIVER)=' {$gatewayEnv} > {$gatewayEnvTmp} || true",
            "mv {$gatewayEnvTmp} {$gatewayEnv}",
            "printf '\\nDB_DATABASE=%s\\nSESSION_DRIVER=file\\n' {$gatewayDatabase} >> {$gatewayEnv}",
            "touch {$gatewayDatabase}",
        ]);
    }

    private static function appKeyCommand(bool $dockerTopology = false, bool $runArtisanInDockerRuntime = false, ?string $remotePath = null, ?string $dockerRuntimeContainer = null, bool $sourceMountedCheckout = false): string
    {
        $envPath = escapeshellarg(self::gatewayStatePath('.env'));

        return implode(' && ', [
            "(grep -q '^APP_KEY=' {$envPath} || printf '%s\\n' 'APP_KEY=' >> {$envPath})",
            "(grep -Eq '^APP_KEY=base64:.+' {$envPath} || ".self::artisanCommand('key:generate --force --no-interaction --ansi', $runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer, $sourceMountedCheckout).')',
            "grep -Eq '^APP_KEY=base64:.+' {$envPath}",
        ]);
    }

    private static function dockerTopologyProviderEnvCommand(bool $dockerTopology): string
    {
        if (! $dockerTopology) {
            return ':';
        }

        $envPath = escapeshellarg(self::gatewayStatePath('.env'));
        $tmpEnvPath = escapeshellarg(self::gatewayStatePath('.env.tmp'));

        return implode(' && ', [
            "rm -f {$tmpEnvPath}",
            "grep -Ev '^(ORBIT_E2E_TOPOLOGY_PROVIDER)=' {$envPath} > {$tmpEnvPath}",
            "mv {$tmpEnvPath} {$envPath}",
            "printf '%s\\n' 'ORBIT_E2E_TOPOLOGY_PROVIDER=docker' >> {$envPath}",
        ]);
    }

    private static function runtimeStateCommand(string $remotePath, ?string $seedFrom, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null, bool $sourceMountedCheckout = false): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::prepareRuntimeStateCommand($seedFrom, $dockerTopology, $remotePath, $dockerRuntimeContainer, $runArtisanInDockerRuntime, $sourceMountedCheckout),
        ]);
    }

    private static function migrateCommand(string $remotePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, ?bool $runArtisanInDockerRuntime = null, bool $sourceMountedCheckout = false): string
    {
        $runArtisanInDockerRuntime ??= $dockerTopology;
        $commands = [
            'cd '.escapeshellarg($remotePath),
            self::artisanCommand('migrate --force --ansi', $runArtisanInDockerRuntime, $remotePath, $dockerRuntimeContainer, $sourceMountedCheckout),
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
        $caPemPath = rtrim((string) config('orbit.paths.config_root'), '/').'/gateway-ca/orbit.crt';
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

    private static function refreshLocalGatewaySettings(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, string $gatewayApiIp, ?E2EPhaseTimer $timer, bool $sourceMountedCheckout = false): void
    {
        self::runTimed(
            $timer,
            'checkout.gateway-settings',
            fn () => E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::localGatewaySettingsCommand($remotePath, $gatewayApiIp, $sourceMountedCheckout),
                timeoutSeconds: 120,
            ),
        );
    }

    private static function localGatewaySettingsCommand(string $remotePath, string $gatewayApiIp, bool $sourceMountedCheckout = false): string
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
        // AND the gateway root CA recorded as ca_pem_path so its HTTPS calls
        // verify — otherwise CLI calls fail with "Gateway URL is not configured"
        // (no config) or cURL error 60 (CA not trusted). `gateway:add` fetches
        // the CA over http bootstrap, installs trust, verifies node identity,
        // and persists the CLI gateway config (url + ca_pem_path) in one step.
        // Source-mounted runs call the mounted CLI directly so this step cannot
        // accidentally use a stale prepared-image launcher. The WireGuard route
        // to the gateway can take a moment to come up after the topology starts,
        // so retry until it reports success.
        // Best-effort: a node that never reaches the gateway fails its own
        // assertions later with a clear gateway_unavailable error.
        $gatewayIpArg = escapeshellarg($gatewayApiIp);
        $orbitCommand = $sourceMountedCheckout
            ? escapeshellarg("{$remotePath}/apps/cli/orbit")
            : 'orbit';
        $gatewayAddWithRetry = 'i=0; while [ "$i" -lt 8 ]; do '
            .$orbitCommand.' gateway:add '.$gatewayIpArg.' --json 2>&1 | grep -q \'"success"\' && break; '
            .'i=$((i+1)); sleep 3; done; true';

        return 'cd '.escapeshellarg($remotePath)
            .' && '.self::artisanCommand('tinker --execute='.escapeshellarg($php), false, $remotePath, null, $sourceMountedCheckout)
            .' && ('.$gatewayAddWithRetry.')';
    }

    private static function refreshGatewayHostKeys(E2EInstance $instance, string $user, SshKeyPair $keyPair, string $remotePath, ?E2EPhaseTimer $timer, bool $hostLauncher = false, bool $sourceMountedCheckout = false): void
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
                    $sourceMountedCheckout,
                ),
                timeoutSeconds: 120,
            ),
        );
    }

    private static function hostKeyRefreshCommand(string $remotePath, bool $dockerTopology = false, ?string $dockerRuntimeContainer = null, bool $sourceMountedCheckout = false): string
    {
        return implode(' && ', [
            'cd '.escapeshellarg($remotePath),
            self::artisanCommand('orbit:internal:pin-node-host-keys --json', $dockerTopology, $remotePath, $dockerRuntimeContainer, $sourceMountedCheckout),
        ]);
    }

    private static function artisanCommand(string $arguments, bool $dockerTopology, ?string $remotePath = null, ?string $dockerRuntimeContainer = null, bool $sourceMountedCheckout = false): string
    {
        if (! $dockerTopology) {
            $command = 'php apps/gateway/artisan '.$arguments;

            if (! $sourceMountedCheckout) {
                return $command;
            }

            return 'ORBIT_CONFIG_ROOT='.escapeshellarg(self::OrbitConfigRoot).' '.$command;
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

    private static function sourceMountedCheckoutPath(E2EInstance $instance, string $user, bool $hostLauncher): ?string
    {
        if ($instance instanceof SourceMountedCheckoutInstance) {
            $path = $instance->sourceMountedCheckoutPath();

            return is_string($path) && $path !== '' ? $path : null;
        }

        return null;
    }

    private static function hostLauncherActivationCommand(string $remotePath): string
    {
        return implode(' && ', [
            'sudo ln -sfn '.escapeshellarg("{$remotePath}/apps/cli/orbit").' '.escapeshellarg('/usr/local/bin/orbit'),
            'sudo install -d -m 0700 -o orbit -g orbit '.escapeshellarg(self::OrbitConfigRoot),
        ]);
    }

    private static function dockerRuntimeContainerName(E2EInstance $instance): string
    {
        return $instance->name().'-orbit-gateway';
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
        $remoteStorage = escapeshellarg("{$remotePath}/apps/gateway/storage");

        return "mkdir -p {$remoteStorage}/framework/cache/data {$remoteStorage}/framework/sessions {$remoteStorage}/framework/testing {$remoteStorage}/framework/views {$remoteStorage}/logs";
    }

    private static function gatewayStatePath(string $path): string
    {
        return self::OrbitConfigRoot."/{$path}";
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
            './.orbit-e2e-vendor-archives',
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
            './apps/cli/.env',
            './apps/cli/.env.e2e',
            './apps/cli/.env.local',
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
