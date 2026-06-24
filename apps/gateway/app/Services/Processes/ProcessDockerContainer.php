<?php

declare(strict_types=1);

namespace App\Services\Processes;

use InvalidArgumentException;

class ProcessDockerContainer
{
    public const SourceTarget = '/app';

    public const SpecHashLabel = 'orbit.process.spec_hash';

    /** @var array<string, string> */
    private readonly array $environment;

    /** @var list<array{source: string, target: string, read_only: bool}> */
    private readonly array $mounts;

    /** @var list<array{source: string, target: string, read_only: bool}> */
    private readonly array $volumes;

    /** @var list<array{published: int, target: int, protocol: string}> */
    private readonly array $ports;

    /** @var list<string> */
    private readonly array $networkAliases;

    /**
     * @param  array<string, string>  $environment
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @param  list<string>  $networkAliases
     * @param  list<array{source?: string, name?: string, target: string, read_only?: bool}>  $volumes
     * @param  list<array{published: int, target: int, protocol?: string}>  $ports
     */
    public function __construct(
        private readonly string $name,
        private readonly string $image,
        private readonly string $network,
        private readonly string $restartPolicy,
        private readonly string $appSlug,
        private readonly ?string $workspaceSlug,
        private readonly string $processSlug,
        private readonly string $workingDirectory,
        private readonly string $command,
        array $environment,
        array $mounts,
        array $networkAliases,
        array $volumes = [],
        array $ports = [],
        private readonly string $commandMode = 'shell',
    ) {
        $this->environment = $this->normalizeEnvironment($environment);
        $this->mounts = $this->normalizeMounts($mounts);
        $this->volumes = $this->normalizeVolumes($volumes);
        $this->ports = $this->normalizePorts($ports);
        $this->networkAliases = $this->normalizeNetworkAliases($networkAliases);
        $this->assertCommandMode($commandMode);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function image(): string
    {
        return $this->image;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function restartPolicy(): string
    {
        return $this->restartPolicy;
    }

    public function appSlug(): string
    {
        return $this->appSlug;
    }

    public function workspaceSlug(): ?string
    {
        return $this->workspaceSlug;
    }

    public function processSlug(): string
    {
        return $this->processSlug;
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function commandMode(): string
    {
        return $this->commandMode;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    public function volumes(): array
    {
        return $this->volumes;
    }

    /** @return list<array{published: int, target: int, protocol: string}> */
    public function ports(): array
    {
        return $this->ports;
    }

    /** @return list<string> */
    public function networkAliases(): array
    {
        return $this->networkAliases;
    }

    /** @return list<string> */
    public function publishedPorts(): array
    {
        return array_map(
            static fn (array $port): string => "{$port['published']}:{$port['target']}".($port['protocol'] === 'tcp' ? '' : "/{$port['protocol']}"),
            $this->ports,
        );
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'process-runtime',
            'orbit.app' => $this->appSlug,
            'orbit.process' => $this->processSlug,
            self::SpecHashLabel => $this->specHash(),
        ];

        if ($this->workspaceSlug !== null) {
            $labels['orbit.workspace'] = $this->workspaceSlug;
        }

        ksort($labels);

        return $labels;
    }

    public function specHash(): string
    {
        return hash('sha256', json_encode($this->spec(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     app_slug: string,
     *     workspace_slug: string|null,
     *     process_slug: string,
     *     working_directory: string,
     *     command: string,
     *     command_mode: string,
     *     environment: array<string, string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     volumes: list<array{source: string, target: string, read_only: bool}>,
     *     ports: list<array{published: int, target: int, protocol: string}>,
     *     network_aliases: list<string>
     * }
     */
    public function spec(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'network' => $this->network,
            'restart_policy' => $this->restartPolicy,
            'app_slug' => $this->appSlug,
            'workspace_slug' => $this->workspaceSlug,
            'process_slug' => $this->processSlug,
            'working_directory' => $this->workingDirectory,
            'command' => $this->command,
            'command_mode' => $this->commandMode,
            'environment' => $this->environment,
            'mounts' => $this->mounts,
            'volumes' => $this->volumes,
            'ports' => $this->ports,
            'network_aliases' => $this->networkAliases,
        ];
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function normalizeEnvironment(array $environment): array
    {
        ksort($environment);

        return $environment;
    }

    /**
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function normalizeMounts(array $mounts): array
    {
        return array_map(function (array $mount): array {
            $source = trim($mount['source']);
            $target = trim($mount['target']);

            if ($source === '' || $target === '') {
                throw new InvalidArgumentException('Process docker container mounts require source and target paths.');
            }

            return [
                'source' => $source,
                'target' => $target,
                'read_only' => (bool) ($mount['read_only'] ?? false),
            ];
        }, $mounts);
    }

    /**
     * @param  list<array{source?: string, name?: string, target: string, read_only?: bool}>  $volumes
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function normalizeVolumes(array $volumes): array
    {
        return array_map(function (array $volume): array {
            $source = trim($volume['source'] ?? $volume['name'] ?? '');
            $target = trim($volume['target']);

            if ($source === '' || $target === '') {
                throw new InvalidArgumentException('Process docker container volumes require source or name and target paths.');
            }

            return [
                'source' => $source,
                'target' => $target,
                'read_only' => (bool) ($volume['read_only'] ?? false),
            ];
        }, $volumes);
    }

    /**
     * @param  list<string>  $networkAliases
     * @return list<string>
     */
    private function normalizeNetworkAliases(array $networkAliases): array
    {
        $aliases = array_values(array_unique(array_filter(
            array_map(trim(...), $networkAliases),
            fn (string $alias): bool => $alias !== '',
        )));

        sort($aliases);

        return $aliases;
    }

    /**
     * @param  list<array{published: int, target: int, protocol?: string}>  $ports
     * @return list<array{published: int, target: int, protocol: string}>
     */
    private function normalizePorts(array $ports): array
    {
        return array_map(function (array $port): array {
            $published = $port['published'];
            $target = $port['target'];
            $protocol = trim((string) ($port['protocol'] ?? 'tcp'));

            if ($published < 1 || $published > 65535 || $target < 1 || $target > 65535) {
                throw new InvalidArgumentException('Process docker container ports require valid published and target ports.');
            }

            if (! in_array($protocol, ['tcp', 'udp'], true)) {
                throw new InvalidArgumentException('Process docker container ports support tcp or udp only.');
            }

            return [
                'published' => $published,
                'target' => $target,
                'protocol' => $protocol,
            ];
        }, $ports);
    }

    private function assertCommandMode(string $commandMode): void
    {
        if (in_array($commandMode, ['shell', 'image_entrypoint'], true)) {
            return;
        }

        throw new InvalidArgumentException('Process docker container command mode must be shell or image_entrypoint.');
    }
}
