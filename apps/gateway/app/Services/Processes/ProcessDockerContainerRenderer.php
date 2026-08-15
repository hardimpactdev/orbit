<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\LaravelViteDevServerEnvironment;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Workspaces\WorkspacePlacement;
use InvalidArgumentException;

final readonly class ProcessDockerContainerRenderer
{
    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
        private LaravelViteDevServerEnvironment $vite,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function render(?App $app, Process $process, ?Workspace $workspace = null): ProcessDockerContainer
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return $this->renderNodeProcess($process->owner, $process);
        }

        assert($app instanceof App);

        $runtime = $app->runtimeKind();

        if ($runtime !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "App '{$app->name}' uses runtime kind '{$runtime->value}' and cannot back a Docker process runtime unit.",
            );
        }

        $phpVersion = $this->resolvePhpVersion($app, $workspace, $process);

        if ($phpVersion === null) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' on app '{$app->name}' has no resolvable PHP version; cannot render Docker process runtime container.",
            );
        }

        $sourcePath = $this->resolveSourcePath($app, $workspace, $process);

        $policy = $this->phpRuntimePolicy->forVersion($phpVersion);

        $name = $this->containerName($app, $process, $workspace);

        return new ProcessDockerContainer(
            name: $name,
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: $this->restartPolicy($app, $process),
            appSlug: $app->name,
            workspaceSlug: $workspace?->name,
            processSlug: $process->name,
            workingDirectory: ProcessDockerContainer::SourceTarget,
            command: $process->command,
            environment: $this->environmentFor($app, $process, $workspace, $phpVersion),
            mounts: [
                [
                    'source' => $sourcePath,
                    'target' => ProcessDockerContainer::SourceTarget,
                    'read_only' => false,
                ],
                ...$this->vite->containerCertificateMounts($app, $workspace, $process->instance),
            ],
            networkAliases: [$name],
            ports: [],
        );
    }

    public function containerName(?App $app, Process $process, ?Workspace $workspace = null): string
    {
        $process->loadMissing('owner');

        $configuredName = $this->configuredContainerName($process);

        if ($configuredName !== null) {
            return $configuredName;
        }

        if ($process->owner instanceof Node) {
            return $this->assertIdentitySlug($process->name);
        }

        assert($app instanceof App);

        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        if ($workspace instanceof Workspace) {
            $this->assertIdentitySlug($workspace->name);
        }

        return ProcessRuntimeUnitName::for($app, $process, $workspace);
    }

    private function configuredContainerName(Process $process): ?string
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $name = $this->optionalConfigString($config, 'container_name');

        if ($name === null) {
            return null;
        }

        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
            throw new InvalidArgumentException("Unsafe Docker process runtime container name: {$name}");
        }

        return $name;
    }

    private function restartPolicy(?App $app, Process $process): string
    {
        assert($app instanceof App);
        $process->loadMissing('instance');

        return $this->placement->runtimeNode($app, $process->instance)?->hasActiveRole('app-dev') === true
        && $process->restart_policy === ProcessRestartPolicy::Always
            ? 'unless-stopped'
            : $process->restart_policy->toDocker();
    }

    private function renderNodeProcess(Node $node, Process $process): ProcessDockerContainer
    {
        $name = $this->configuredContainerName($process) ?? $this->assertIdentitySlug($process->name);
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $command = trim($process->command);
        $volumes = $this->volumes($config['volumes'] ?? []);
        $mounts = $this->mountsWithoutVolumeTargets(
            mounts: $this->mounts($config['mounts'] ?? []),
            volumes: $volumes,
        );

        if ($command === '') {
            throw new InvalidArgumentException(
                "Node process '{$process->name}' has no command; cannot render Docker process runtime container.",
            );
        }

        return new ProcessDockerContainer(
            name: $name,
            image: $this->requiredConfigString($config, 'image', $process),
            network: $this->optionalConfigString($config, 'network') ?? $this->names->network(),
            restartPolicy: $process->restart_policy->toDocker(),
            appSlug: $node->name,
            workspaceSlug: null,
            processSlug: $process->name,
            workingDirectory: $this->optionalConfigString($config, 'working_directory') ?? '/',
            command: $command,
            environment: [
                ...$this->stringMap($config['environment'] ?? []),
                ...$this->secretEnvironment($process),
            ],
            mounts: $mounts,
            networkAliases: array_values(array_unique([
                $name,
                ...$this->stringList($config['network_aliases'] ?? []),
            ])),
            volumes: $volumes,
            ports: $this->ports($config['ports'] ?? []),
            commandMode: $this->optionalConfigString($config, 'command_mode') ?? 'shell',
        );
    }

    private function resolvePhpVersion(?App $app, ?Workspace $workspace, Process $process): ?string
    {
        if ($workspace instanceof Workspace) {
            $version = $workspace->effectivePhpVersion();

            return is_string($version) && trim($version) !== '' ? trim($version) : null;
        }

        assert($app instanceof App);
        $process->loadMissing('instance');
        $version = $this->placement->runtimePhpVersion($app, $process->instance);

        return trim($version) !== '' ? trim($version) : null;
    }

    private function resolveSourcePath(?App $app, ?Workspace $workspace, Process $process): string
    {
        assert($app instanceof App);
        $process->loadMissing('instance');
        $path = $workspace instanceof Workspace
            ? $workspace->path
            : $this->placement->runtimePath($app, $process->instance);
        $path = rtrim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException(
                "Process '{$process->name}' on app '{$app->name}' has no source path; cannot render Docker process runtime container.",
            );
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(?App $app, Process $process, ?Workspace $workspace, string $phpVersion): array
    {
        assert($app instanceof App);
        $home = '/root';

        $environment =
            [
                'PATH' => '/app/vendor/bin:/app/node_modules/.bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'HOME' => $home,
                'ORBIT_APP' => $app->name,
                'ORBIT_PHP_VERSION' => $phpVersion,
            ] + $this->vite->containerVariables($app, $workspace, $process->instance);

        if ($workspace instanceof Workspace) {
            $environment['ORBIT_WORKSPACE'] = $workspace->name;
        }

        return $environment;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requiredConfigString(array $config, string $key, Process $process): string
    {
        $value = $this->optionalConfigString($config, $key);

        if ($value !== null) {
            return $value;
        }

        throw new InvalidArgumentException(
            "Node process '{$process->name}' is missing runtime_config.{$key}; cannot render Docker process runtime container.",
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalConfigString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            if (is_scalar($item)) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function secretEnvironment(Process $process): array
    {
        $credentials = is_array($process->credentials) ? $process->credentials : [];

        return $this->stringMap($credentials['environment'] ?? []);
    }

    /**
     * @return list<array{source: string, target: string, read_only?: bool}>
     */
    private function mounts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(function (mixed $mount): ?array {
                if (! is_array($mount)) {
                    return null;
                }

                $source = $mount['source'] ?? null;
                $target = $mount['target'] ?? null;

                if (! is_string($source) || ! is_string($target)) {
                    return null;
                }

                return [
                    'source' => $source,
                    'target' => $target,
                    'read_only' => (bool) ($mount['read_only'] ?? false),
                ];
            }, $value),
        ));
    }

    /**
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @param  list<array{source: string, target: string, read_only?: bool}>  $volumes
     * @return list<array{source: string, target: string, read_only?: bool}>
     */
    private function mountsWithoutVolumeTargets(array $mounts, array $volumes): array
    {
        $volumeTargets = array_flip(array_column($volumes, 'target'));

        return array_values(array_filter(
            $mounts,
            fn (array $mount): bool => ! isset($volumeTargets[$mount['target']]),
        ));
    }

    /**
     * @return list<array{source: string, target: string, read_only?: bool}>
     */
    private function volumes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(function (mixed $volume): ?array {
                if (! is_array($volume)) {
                    return null;
                }

                $source = $volume['source'] ?? $volume['name'] ?? null;
                $target = $volume['target'] ?? null;

                if (! is_string($source) || ! is_string($target)) {
                    return null;
                }

                return [
                    'source' => $source,
                    'target' => $target,
                    'read_only' => (bool) ($volume['read_only'] ?? false),
                ];
            }, $value),
        ));
    }

    /**
     * @return list<array{host?: string, published: int, target: int, protocol?: string}>
     */
    private function ports(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(function (mixed $port): ?array {
                if (! is_array($port)) {
                    return null;
                }

                $published = $port['published'] ?? null;
                $target = $port['target'] ?? null;

                if (! is_int($published) || ! is_int($target)) {
                    return null;
                }

                $protocol = $port['protocol'] ?? 'tcp';
                $host = $port['host'] ?? null;

                $normalized = [
                    'published' => $published,
                    'target' => $target,
                    'protocol' => is_string($protocol) ? $protocol : 'tcp',
                ];

                if (is_string($host) && trim($host) !== '') {
                    $normalized['host'] = trim($host);
                }

                return $normalized;
            }, $value),
        ));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function assertIdentitySlug(string $value): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe Docker process runtime identity segment: {$value}");
        }

        return $value;
    }
}
