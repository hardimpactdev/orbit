<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class LocalDockerProcessContainerSpec
{
    public const string SPEC_HASH_LABEL = 'orbit.process.spec_hash';

    public const string SOURCE_TARGET = '/app';

    /**
     * @param  array<string, string>  $environment
     * @param  list<array{source: string, target: string, read_only: bool}>  $mounts
     * @param  list<array{source: string, target: string, read_only: bool}>  $volumes
     * @param  list<array{host: string|null, published: int, target: int, protocol: string}>  $ports
     * @param  list<string>  $networkAliases
     */
    private function __construct(
        public string $name,
        public string $image,
        public string $network,
        public string $restartPolicy,
        public string $appSlug,
        public ?string $workspaceSlug,
        public string $processSlug,
        public string $workingDirectory,
        public string $command,
        public string $commandMode,
        public array $environment,
        public array $mounts,
        public array $volumes,
        public array $ports,
        public array $networkAliases,
        public string $expectedHash,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value)) {
            throw self::validationFailure('spec');
        }

        return new self(
            name: self::identifier($value, 'name'),
            image: self::nonEmptyString($value, 'image'),
            network: self::identifier($value, 'network'),
            restartPolicy: self::restartPolicy($value['restart_policy'] ?? null),
            appSlug: self::identifier($value, 'app_slug'),
            workspaceSlug: self::nullableIdentifier($value['workspace_slug'] ?? null, 'workspace_slug'),
            processSlug: self::identifier($value, 'process_slug'),
            workingDirectory: self::absolutePath($value, 'working_directory'),
            command: self::nonEmptyString($value, 'command'),
            commandMode: self::commandMode($value['command_mode'] ?? null),
            environment: self::environment($value['environment'] ?? null),
            mounts: self::mounts($value['mounts'] ?? null),
            volumes: self::mounts($value['volumes'] ?? []),
            ports: self::ports($value['ports'] ?? []),
            networkAliases: self::networkAliases($value['network_aliases'] ?? []),
            expectedHash: self::hash($value['expected_hash'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    public function createCommand(): array
    {
        $command = [
            'docker',
            'create',
            '--pull',
            'never',
            '--name',
            $this->name,
            '--restart',
            $this->restartPolicy,
            '--network',
            $this->networkForDocker(),
        ];

        if (! $this->usesE2eNodeNetwork()) {
            foreach ($this->publishedPorts() as $port) {
                $command[] = '--publish';
                $command[] = $port;
            }
        }

        if ($this->usesShellEntrypoint()) {
            $command[] = '--workdir';
            $command[] = $this->workingDirectory;
            $command[] = '--entrypoint';
            $command[] = 'sh';
        }

        if (! $this->usesE2eNodeNetwork()) {
            foreach ($this->networkAliases as $alias) {
                $command[] = '--network-alias';
                $command[] = $alias;
            }
        }

        foreach ($this->labels() as $key => $labelValue) {
            $command[] = '--label';
            $command[] = "{$key}={$labelValue}";
        }

        foreach ($this->environment as $key => $environmentValue) {
            $command[] = '--env';
            $command[] = "{$key}={$environmentValue}";
        }

        foreach ($this->mounts as $mount) {
            $command[] = '--mount';
            $command[] = $this->bindMountSpec($mount);
        }

        foreach ($this->volumes as $volume) {
            $command[] = '--mount';
            $command[] = $this->volumeMountSpec($volume);
        }

        $command[] = $this->image;

        if ($this->usesShellEntrypoint()) {
            $command[] = '-lc';
            $command[] = $this->command;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function bindMountSources(): array
    {
        $sources = array_map(
            static fn (array $mount): string => $mount['source'],
            $this->mounts,
        );

        return array_values(array_unique($sources));
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'process-runtime',
            'orbit.app' => $this->appSlug,
            'orbit.process' => $this->processSlug,
            self::SPEC_HASH_LABEL => $this->expectedHash,
        ];

        if ($this->workspaceSlug !== null) {
            $labels['orbit.workspace'] = $this->workspaceSlug;
        }

        ksort($labels);

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function publishedPorts(): array
    {
        return array_map(
            static fn (array $port): string => (
                self::publishedHost($port['host'])
                ."{$port['published']}:{$port['target']}"
                .($port['protocol'] === 'tcp' ? '' : "/{$port['protocol']}")
            ),
            $this->ports,
        );
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $mount
     */
    private function bindMountSpec(array $mount): string
    {
        return $this->mountSpec('bind', $mount);
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $volume
     */
    private function volumeMountSpec(array $volume): string
    {
        return $this->mountSpec('volume', $volume);
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $mount
     */
    private function mountSpec(string $type, array $mount): string
    {
        $fields = [
            "type={$type}",
            $this->mountField('source', $mount['source']),
            $this->mountField('target', $mount['target']),
        ];

        if ($mount['read_only']) {
            $fields[] = 'readonly';
        }

        return implode(',', $fields);
    }

    private function mountField(string $key, string $value): string
    {
        $field = "{$key}={$value}";

        if (str_contains($field, ',') || str_contains($field, '"')) {
            return '"'.str_replace(search: '"', replace: '""', subject: $field).'"';
        }

        return $field;
    }

    private function usesShellEntrypoint(): bool
    {
        return $this->commandMode === 'shell';
    }

    private function networkForDocker(): string
    {
        $nodeContainer = $this->e2eNodeContainer();

        if ($nodeContainer !== null) {
            return "container:{$nodeContainer}";
        }

        return $this->network;
    }

    private function usesE2eNodeNetwork(): bool
    {
        return $this->e2eNodeContainer() !== null;
    }

    private function e2eNodeContainer(): ?string
    {
        $network = getenv('ORBIT_E2E_DOCKER_NETWORK');

        if (! is_string($network) || trim($network) === '') {
            return null;
        }

        $nodeContainer = getenv('ORBIT_NODE_CONTAINER');

        return is_string($nodeContainer) && trim($nodeContainer) !== '' ? trim($nodeContainer) : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function identifier(array $payload, string $key): string
    {
        /** @var mixed $value */
        $value = $payload[$key] ?? null;

        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure($key);
    }

    private static function nullableIdentifier(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function absolutePath(array $payload, string $key): string
    {
        $value = self::nonEmptyString($payload, $key);

        if (str_starts_with($value, '/')) {
            return $value;
        }

        throw self::validationFailure($key);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function nonEmptyString(array $payload, string $key): string
    {
        /** @var mixed $value */
        $value = $payload[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw self::validationFailure($key);
    }

    private static function restartPolicy(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['no', 'always', 'unless-stopped', 'on-failure'], strict: true)) {
            return $value;
        }

        throw self::validationFailure('restart_policy');
    }

    private static function commandMode(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['shell', 'image_entrypoint'], strict: true)) {
            return $value;
        }

        throw self::validationFailure('command_mode');
    }

    /**
     * @return array<string, string>
     */
    private static function environment(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::validationFailure('environment');
        }

        $environment = [];

        /** @var array<array-key, mixed> $items */
        $items = $value;

        foreach ($items as $key => $environmentValue) {
            if (! is_string($key) || ! is_string($environmentValue)) {
                throw self::validationFailure('environment');
            }

            $environment[$key] = $environmentValue;
        }

        ksort($environment);

        return $environment;
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private static function mounts(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::validationFailure('mounts');
        }

        return array_map(self::mount(...), $value);
    }

    /**
     * @return array{source: string, target: string, read_only: bool}
     */
    private static function mount(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::validationFailure('mounts');
        }

        /** @var array<array-key, mixed> $mount */
        $mount = $value;
        /** @var mixed $source */
        $source = $mount['source'] ?? null;
        /** @var mixed $target */
        $target = $mount['target'] ?? null;
        /** @var mixed $readOnly */
        $readOnly = $mount['read_only'] ?? false;

        if (! is_string($source) || trim($source) === '' || ! is_string($target) || trim($target) === '') {
            throw self::validationFailure('mounts');
        }

        if (! is_bool($readOnly)) {
            throw self::validationFailure('mounts');
        }

        return [
            'source' => trim($source),
            'target' => trim($target),
            'read_only' => $readOnly,
        ];
    }

    /**
     * @return list<array{host: string|null, published: int, target: int, protocol: string}>
     */
    private static function ports(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::validationFailure('ports');
        }

        return array_map(self::port(...), $value);
    }

    /**
     * @return array{host: string|null, published: int, target: int, protocol: string}
     */
    private static function port(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::validationFailure('ports');
        }

        /** @var array<array-key, mixed> $port */
        $port = $value;
        /** @var mixed $published */
        $published = $port['published'] ?? null;
        /** @var mixed $target */
        $target = $port['target'] ?? null;
        /** @var mixed $protocol */
        $protocol = $port['protocol'] ?? 'tcp';
        /** @var mixed $host */
        $host = $port['host'] ?? null;

        if (! is_int($published) || ! is_int($target) || $published < 1 || $target < 1) {
            throw self::validationFailure('ports');
        }

        if (! is_string($protocol) || ! in_array($protocol, ['tcp', 'udp'], strict: true)) {
            throw self::validationFailure('ports');
        }

        if ($host !== null && (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false)) {
            throw self::validationFailure('ports');
        }

        return [
            'host' => $host,
            'published' => $published,
            'target' => $target,
            'protocol' => $protocol,
        ];
    }

    private static function publishedHost(?string $host): string
    {
        if ($host === null) {
            return '';
        }

        return str_contains($host, ':') ? "[{$host}]:" : "{$host}:";
    }

    /**
     * @return list<string>
     */
    private static function networkAliases(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::validationFailure('network_aliases');
        }

        $aliases = [];

        /** @var list<mixed> $items */
        $items = $value;

        foreach ($items as $alias) {
            if (! is_string($alias) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $alias) !== 1) {
                throw self::validationFailure('network_aliases');
            }

            $aliases[] = $alias;
        }

        sort($aliases);

        return array_values(array_unique($aliases));
    }

    private static function hash(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('expected_hash');
    }

    private static function validationFailure(string $field): LocalDockerContainerFailure
    {
        return new LocalDockerContainerFailure(
            errorCode: 'validation_failed',
            message: 'Docker process container spec is invalid.',
            meta: ['field' => $field],
        );
    }
}
