<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class E2ECurrentCheckout
{
    private static ?string $cachedArchive = null;

    /** @var array<string, string> */
    private static array $cachedBasePaths = [];

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
    public static function installOnTopology(E2ETopologyLease $topology, ?array $roles = null, array $users = []): array
    {
        $roles ??= self::availableTopologyRoles($topology);

        $paths = [];

        foreach ($roles as $role) {
            [$instance, $user, $seedFrom] = self::topologyRoleTarget($topology, $role, $users);

            $paths[$role] = self::install(
                $instance,
                $user,
                $topology->sshKeyPair(),
                seedFrom: $seedFrom,
            );
        }

        return $paths;
    }

    public static function install(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom = null): string
    {
        if (self::checkoutCacheEnabled()) {
            return self::installFromCachedBase($instance, $user, $keyPair, $seedFrom);
        }

        $remotePath = "/home/{$user}/orbit-current";
        $tarball = self::buildArchive();

        try {
            self::copyArchive($tarball, $instance);

            E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::installCommand($remotePath, $seedFrom ?? "/home/{$user}/orbit", $seedFrom),
                timeoutSeconds: 600,
            );

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

        if (self::$cachedArchive !== null && is_file(self::$cachedArchive)) {
            @unlink(self::$cachedArchive);
        }

        self::$cachedArchive = null;
    }

    private static function checkoutCacheEnabled(): bool
    {
        $value = getenv('ORBIT_E2E_CHECKOUT_CACHE');

        return is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'yes', 'process'], true);
    }

    private static function installFromCachedBase(E2EInstance $instance, string $user, SshKeyPair $keyPair, ?string $seedFrom): string
    {
        $cacheKey = implode('|', [$instance->name(), $user, $seedFrom ?? '']);
        $basePath = "/home/{$user}/orbit-current-base-".substr(sha1($cacheKey), 0, 10);

        if (! isset(self::$cachedBasePaths[$cacheKey])) {
            $tarball = self::cachedArchive();

            self::copyArchive($tarball, $instance);

            E2ECommand::ssh(
                $instance,
                $user,
                $keyPair,
                self::installCommand($basePath, $seedFrom ?? "/home/{$user}/orbit", $seedFrom),
                timeoutSeconds: 600,
            );

            self::$cachedBasePaths[$cacheKey] = $basePath;
        }

        $remotePath = "/home/{$user}/orbit-current-".bin2hex(random_bytes(4));

        E2ECommand::ssh(
            $instance,
            $user,
            $keyPair,
            self::cloneCachedCheckoutCommand(self::$cachedBasePaths[$cacheKey], $remotePath),
            timeoutSeconds: 120,
        );

        return $remotePath;
    }

    private static function installCommand(string $remotePath, string $vendorSourcePath, ?string $seedFrom): string
    {
        return implode(' && ', [
            'rm -rf '.escapeshellarg($remotePath),
            'mkdir -p '.escapeshellarg($remotePath),
            'tar --warning=no-unknown-keyword -xzf /tmp/orbit-current.tar.gz -C '.escapeshellarg($remotePath),
            'sudo rm -f /tmp/orbit-current.tar.gz',
            'cd '.escapeshellarg($remotePath),
            self::installComposerDependenciesCommand($vendorSourcePath),
            self::prepareRuntimeStateCommand($seedFrom),
            'php artisan migrate --force --ansi',
        ]);
    }

    private static function installComposerDependenciesCommand(string $vendorSourcePath): string
    {
        $sourceLock = escapeshellarg("{$vendorSourcePath}/composer.lock");
        $sourceAutoload = escapeshellarg("{$vendorSourcePath}/vendor/autoload.php");
        $sourceBoost = escapeshellarg("{$vendorSourcePath}/vendor/laravel/boost");
        $sourceVendor = escapeshellarg("{$vendorSourcePath}/vendor");

        return "if [ -f {$sourceAutoload} ] && [ -d {$sourceBoost} ] && [ -f {$sourceLock} ] && cmp -s {$sourceLock} composer.lock; then cp -al {$sourceVendor} vendor 2>/dev/null || cp -a {$sourceVendor} vendor; else composer install --no-interaction --prefer-dist --optimize-autoloader; fi";
    }

    private static function prepareRuntimeStateCommand(?string $seedFrom): string
    {
        if ($seedFrom === null) {
            return 'cp .env.example .env && mkdir -p database && touch database/database.sqlite && php artisan key:generate --ansi';
        }

        $seedEnv = escapeshellarg("{$seedFrom}/.env");
        $seedDatabase = escapeshellarg("{$seedFrom}/database/database.sqlite");
        $seedStorageApp = escapeshellarg("{$seedFrom}/storage/app");

        return implode(' && ', [
            "if [ -f {$seedEnv} ]; then cp {$seedEnv} .env; else cp .env.example .env; fi",
            'mkdir -p database',
            "if [ -f {$seedDatabase} ]; then cp {$seedDatabase} database/database.sqlite; else touch database/database.sqlite; fi",
            "if [ -d {$seedStorageApp} ]; then mkdir -p storage && rm -rf storage/app && cp -a {$seedStorageApp} storage/app; fi",
            "grep -q '^APP_KEY=base64:' .env || php artisan key:generate --ansi",
        ]);
    }

    private static function cloneCachedCheckoutCommand(string $basePath, string $remotePath): string
    {
        $basePath = escapeshellarg($basePath);
        $remotePath = escapeshellarg($remotePath);

        return implode(' && ', [
            "rm -rf {$remotePath}",
            "cp -al {$basePath} {$remotePath}",
            "rm -rf {$remotePath}/database {$remotePath}/storage {$remotePath}/.env",
            "cp {$basePath}/.env {$remotePath}/.env",
            "mkdir -p {$remotePath}/database",
            "if [ -f {$basePath}/database/database.sqlite ]; then cp {$basePath}/database/database.sqlite {$remotePath}/database/database.sqlite; else touch {$remotePath}/database/database.sqlite; fi",
            "if [ -d {$basePath}/storage ]; then cp -a {$basePath}/storage {$remotePath}/storage; fi",
        ]);
    }

    /**
     * @return list<string>
     */
    private static function availableTopologyRoles(E2ETopologyLease $topology): array
    {
        $roles = ['control'];

        if ($topology->gateway() !== null) {
            $roles[] = 'gateway';
        }

        if ($topology->devApp() !== null) {
            $roles[] = 'dev';
        }

        if ($topology->prodApp() !== null) {
            $roles[] = 'prod';
        }

        return $roles;
    }

    /**
     * @return array{0: E2EInstance, 1: string, 2: string}
     */
    private static function topologyRoleTarget(E2ETopologyLease $topology, string $role, array $users): array
    {
        $controlUser = $users['control'] ?? E2EConfig::fromEnvironment()->controlUser;

        return match ($role) {
            'control' => [$topology->control(), $controlUser, "/home/{$controlUser}/orbit"],
            'gateway' => self::requiredRole($topology->gateway(), $role, $users['gateway'] ?? 'orbit'),
            'dev' => self::requiredRole($topology->devApp(), $role, $users['dev'] ?? 'orbit'),
            'prod' => self::requiredRole($topology->prodApp(), $role, $users['prod'] ?? 'orbit'),
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

    private static function buildArchive(): string
    {
        $tarball = sys_get_temp_dir().'/orbit-current-'.bin2hex(random_bytes(6)).'.tar.gz';

        $excludes = [
            './.git',
            './.env',
            './database/*.sqlite',
            './database/*.sqlite-*',
            './node_modules',
            './storage/framework/cache/data/*',
            './storage/framework/sessions/*',
            './storage/framework/testing/*',
            './storage/framework/views/*',
            './storage/logs/*',
            './vendor',
        ];

        $excludeArgs = implode(' ', array_map(
            fn (string $pattern): string => '--exclude='.escapeshellarg($pattern),
            $excludes,
        ));

        $result = Process::timeout(300)->run(sprintf(
            'COPYFILE_DISABLE=1 tar %s -czf %s -C %s .',
            $excludeArgs,
            escapeshellarg($tarball),
            escapeshellarg(base_path()),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to build current checkout archive: {$result->errorOutput()}");
        }

        return $tarball;
    }

    private static function cachedArchive(): string
    {
        if (self::$cachedArchive !== null) {
            return self::$cachedArchive;
        }

        self::$cachedArchive = self::buildArchive();

        return self::$cachedArchive;
    }

    private static function copyArchive(string $tarball, E2EInstance $instance): void
    {
        if ($instance instanceof IncusInstance) {
            $instance->copyLocalFileToInstance($tarball, '/tmp/orbit-current.tar.gz');

            return;
        }

        $instance->copyFileToInstance($tarball, '/tmp/orbit-current.tar.gz');
    }
}
