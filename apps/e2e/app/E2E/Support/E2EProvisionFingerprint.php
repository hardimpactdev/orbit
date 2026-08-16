<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class E2EProvisionFingerprint
{
    public const int SchemaVersion = 1;

    /**
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    public static function fromRoot(
        string $root,
        E2ETopologyKind $kind,
        array $environment = [],
        string $baseImageIdentity = 'unknown',
        ?string $bundleDirectory = null,
    ): array {
        $root = rtrim($root, '/');
        $roleDag = self::roleDag($kind);
        $inputs = [
            'base_image' => $baseImageIdentity,
            'environment' => self::filteredEnvironment($environment),
            'source' => [
                'provision' => self::hashPathSet($root, self::provisionSourcePaths()),
                'cli_artifact' => self::hashPathSet($root, self::cliArtifactPaths()),
                'gateway_artifact' => E2EGatewayImageBuildInputs::inventory($root),
                'websocket_artifact' => self::hashPathSet($root, self::webSocketArtifactPaths()),
            ],
            'runtime_archives' => self::runtimeArchiveFingerprints($bundleDirectory),
        ];

        $globalInput = [
            'schema_version' => self::SchemaVersion,
            'topology_kind' => $kind->value,
            'role_dag' => $roleDag,
            'base_image' => $inputs['base_image'],
            'environment' => $inputs['environment'],
            'provision' => $inputs['source']['provision']['hash'],
        ];
        $fingerprints = [
            'provision' => self::hashValue($globalInput),
            'cli_artifact' => $inputs['source']['cli_artifact']['hash'],
            'gateway_artifact' => $inputs['source']['gateway_artifact']['hash'],
            'websocket_artifact' => $inputs['source']['websocket_artifact']['hash'],
            'runtime_archives' => self::hashValue($inputs['runtime_archives']),
        ];
        $fingerprints['global'] = self::hashValue($fingerprints);

        $roleFingerprints = [];

        foreach (array_keys($roleDag) as $role) {
            $roleFingerprints[$role] = self::hashValue([
                'provision' => $fingerprints['provision'],
                'cli_artifact' => $fingerprints['cli_artifact'],
                'gateway_artifact' => self::roleDependsOnGatewayState($role) ? $fingerprints['gateway_artifact'] : null,
                'websocket_artifact' => $role === 'dev' ? $fingerprints['websocket_artifact'] : null,
            ]);
        }

        return [
            'schema_version' => self::SchemaVersion,
            'topology_kind' => $kind->value,
            'role_dag' => $roleDag,
            'inputs' => $inputs,
            'fingerprints' => $fingerprints,
            'role_fingerprints' => $roleFingerprints,
        ];
    }

    public static function fromHost(IncusHost $host, E2ETopologyKind $kind, ?string $bundleDirectory = null): array
    {
        return self::fromRoot(
            root: repo_path(),
            kind: $kind,
            environment: self::environmentFromProcess(),
            baseImageIdentity: $host->imageIdentity($host->config->baseImage),
            bundleDirectory: $bundleDirectory,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roleDag(E2ETopologyKind $kind): array
    {
        $dag = [];

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            $dag[$role] = match ($role) {
                'operator' => [],
                'gateway' => ['operator'],
                default => ['gateway'],
            };
        }

        return $dag;
    }

    private static function roleDependsOnGatewayState(string $role): bool
    {
        return $role !== 'operator';
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private static function filteredEnvironment(array $environment): array
    {
        $keys = [
            'ORBIT_E2E_ARCH',
            'ORBIT_E2E_BASE_IMAGE',
            'ORBIT_E2E_BOOTSTRAP_USER',
            'ORBIT_E2E_HOST',
            'ORBIT_E2E_INCUS_HOSTS',
            'ORBIT_E2E_INCUS_STORAGE_POOL',
            'ORBIT_E2E_OPERATOR_USER',
            'ORBIT_E2E_PROVIDER',
            'ORBIT_E2E_PROVIDERS',
            'ORBIT_E2E_SOURCE_IMAGE',
            'ORBIT_E2E_TIMEOUT_SECONDS',
            'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE',
        ];

        $filtered = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $environment)) {
                continue;
            }

            $filtered[$key] = $environment[$key];
        }

        ksort($filtered);

        return $filtered;
    }

    /**
     * @return array<string, string>
     */
    private static function environmentFromProcess(): array
    {
        $environment = [];

        foreach ($_ENV as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $environment[$key] = (string) $value;
        }

        foreach ($_SERVER as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'ORBIT_E2E_') || ! is_scalar($value)) {
                continue;
            }

            $environment[$key] = (string) $value;
        }

        return $environment;
    }

    /**
     * @return list<string>
     */
    private static function cliArtifactPaths(): array
    {
        return [
            'apps/cli',
            'packages/core',
            'packages/sdk',
            'composer.json',
            'composer.lock',
            'apps/cli/composer.json',
            'apps/cli/composer.lock',
            'bin/orbit',
        ];
    }

    /**
     * @return list<string>
     */
    private static function webSocketArtifactPaths(): array
    {
        return [
            'apps/reverb',
            'docker/orbit-reverb',
        ];
    }

    /**
     * @return list<string>
     */
    private static function provisionSourcePaths(): array
    {
        return [
            'composer.json',
            'bin/install-orbit',
            'bin/e2e-provision-node',
            'bin/_e2e-deps.sh',
            'apps/e2e/app/E2E/Support/DockerTopologyProvider.php',
            'apps/e2e/app/E2E/Support/E2EArtifactProdManifest.php',
            'apps/e2e/app/E2E/Support/E2ECommand.php',
            'apps/e2e/app/E2E/Support/E2EConfig.php',
            'apps/e2e/app/E2E/Support/E2ECurrentCheckout.php',
            'apps/e2e/app/E2E/Support/E2EGatewayApi.php',
            'apps/e2e/app/E2E/Support/E2EImage.php',
            'apps/e2e/app/E2E/Support/E2EOperatorIdentity.php',
            'apps/e2e/app/E2E/Support/E2ETopologyKind.php',
            'apps/e2e/app/E2E/Support/E2EWgEasyGateway.php',
            'apps/e2e/app/E2E/Support/E2EWireGuardIdentitySet.php',
            'apps/e2e/app/E2E/Support/E2EWireGuardMesh.php',
            'apps/e2e/app/E2E/Support/IncusHost.php',
            'apps/e2e/app/E2E/Support/IncusTopologyBuilder.php',
            'apps/e2e/app/E2E/Support/OrbitCliBinaryBundle.php',
            'apps/e2e/app/E2E/Support/SourceMountedCheckoutLifecycleLock.php',
            'apps/e2e/app/E2E/Support/SourceMountedCheckoutMutationFence.php',
            'apps/e2e/app/E2E/Support/SourceMountedCheckoutSyncer.php',
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array{hash: string, files: array<string, string>}
     */
    private static function hashPathSet(string $root, array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = "{$root}/{$path}";

            if (is_file($absolute)) {
                $files[$path] = hash_file('sha256', $absolute) ?: '';

                continue;
            }

            if (! is_dir($absolute)) {
                $files[$path] = 'missing';

                continue;
            }

            foreach (self::filesInDirectory($absolute) as $file) {
                $pathName = $file->getPathname();
                $hash = @hash_file('sha256', $pathName);

                if ($hash === false) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', $pathName), '/');
                $files[$relative] = $hash;
            }
        }

        ksort($files);

        return [
            'hash' => self::hashValue($files),
            'files' => $files,
        ];
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function filesInDirectory(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (self::isIgnoredPath($path)) {
                continue;
            }

            $files[] = $file;
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => $a->getPathname() <=> $b->getPathname());

        return $files;
    }

    private static function isIgnoredPath(string $path): bool
    {
        if (preg_match('#/tests/\.tmp-[^/]+$#', $path) === 1) {
            return true;
        }

        return array_any(
            ['/.git/', '/vendor/', '/node_modules/', '/storage/', '/bootstrap/cache/', '/build/', '/builds/'],
            fn (string $segment): bool => str_contains($path, $segment),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function runtimeArchiveFingerprints(?string $bundleDirectory): array
    {
        if ($bundleDirectory === null || ! is_dir($bundleDirectory)) {
            return [];
        }

        $archives = [
            E2EArtifactProdManifest::GatewayImageArchive,
            'orbit-binary',
            'caddy-2-alpine.tar',
            'dnsmasq-latest.tar',
            'frankenphp-1-php8.5-bookworm.tar',
            'wg-easy-15.tar',
        ];
        $fingerprints = [];

        foreach ($archives as $archive) {
            $path = "{$bundleDirectory}/{$archive}";

            if (! is_file($path)) {
                continue;
            }

            $fingerprints[$archive] = hash_file('sha256', $path) ?: '';
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    private static function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
