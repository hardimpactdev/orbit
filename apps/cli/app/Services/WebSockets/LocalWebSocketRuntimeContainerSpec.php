<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalWebSocketRuntimeContainerSpec
{
    public const string SpecHashLabel = 'orbit.websocket.spec_hash';

    /**
     * @param  array<string, string>  $environment
     * @param  list<array{source: string, target: string, read_only: bool}>  $mounts
     * @param  list<string>  $networkAliases
     * @param  list<string>  $publishedPorts
     */
    private function __construct(
        public string $name,
        public string $image,
        public string $network,
        public string $restartPolicy,
        public string $backendName,
        public int $valkeyNodeId,
        public string $workingDirectory,
        public string $command,
        public array $environment,
        public array $mounts,
        public array $networkAliases,
        public array $publishedPorts,
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
            backendName: self::identifier($value, 'backend_name'),
            valkeyNodeId: self::positiveInteger($value['valkey_node_id'] ?? null, 'valkey_node_id'),
            workingDirectory: self::absolutePath($value, 'working_directory'),
            command: self::nonEmptyString($value, 'command'),
            environment: self::environment($value['environment'] ?? null),
            mounts: self::mounts($value['mounts'] ?? null),
            networkAliases: self::networkAliases($value['network_aliases'] ?? []),
            publishedPorts: self::publishedPorts($value['published_ports'] ?? []),
            expectedHash: self::hash($value['expected_hash'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    public function runCommand(): array
    {
        $command = [
            'docker',
            'run',
            '-d',
            '--pull',
            'never',
            '--name',
            $this->name,
            '--restart',
            $this->restartPolicy,
            '--network',
            $this->networkForDocker(),
            '--workdir',
            $this->workingDirectory,
            '--entrypoint',
            'sh',
        ];

        if (! $this->usesE2eNodeNetwork()) {
            foreach ($this->publishedPorts as $port) {
                $command[] = '--publish';
                $command[] = $port;
            }

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
            $command[] = $this->mountSpec($mount);
        }

        $command[] = $this->image;
        $command[] = '-lc';
        $command[] = $this->command;

        return $command;
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'websocket-runtime',
            'orbit.websocket.backend' => $this->backendName,
            self::SpecHashLabel => $this->expectedHash,
        ];

        ksort($labels);

        return $labels;
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

        $position = strpos($this->name, '-orbit-websocket-');

        if ($position !== false) {
            return substr($this->name, 0, $position) ?: null;
        }

        $nodeContainer = getenv('ORBIT_NODE_CONTAINER');

        return is_string($nodeContainer) && trim($nodeContainer) !== '' ? trim($nodeContainer) : null;
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $mount
     */
    private function mountSpec(array $mount): string
    {
        $fields = [
            'type=bind',
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

    private static function positiveInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw self::validationFailure($field);
    }

    private static function restartPolicy(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['no', 'always', 'unless-stopped', 'on-failure'], strict: true)) {
            return $value;
        }

        throw self::validationFailure('restart_policy');
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

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $value) || ! is_string($value[$key])) {
                throw self::validationFailure('environment');
            }

            $environment[$key] = $value[$key];
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
     * @return list<string>
     */
    private static function networkAliases(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::validationFailure('network_aliases');
        }

        $aliases = [];

        foreach (array_keys($value) as $index) {
            if (! array_key_exists($index, $value) || ! is_string($value[$index])) {
                throw self::validationFailure('network_aliases');
            }

            $alias = $value[$index];

            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $alias) !== 1) {
                throw self::validationFailure('network_aliases');
            }

            $aliases[] = $alias;
        }

        sort($aliases);

        return array_values(array_unique($aliases));
    }

    /**
     * @return list<string>
     */
    private static function publishedPorts(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::validationFailure('published_ports');
        }

        $ports = [];

        foreach ($value as $port) {
            if (! is_string($port) || preg_match('/^[0-9a-fA-F:.]+:\d{1,5}:\d{1,5}(?:\/(?:tcp|udp))?$/', $port) !== 1) {
                throw self::validationFailure('published_ports');
            }

            $ports[] = $port;
        }

        sort($ports);

        return array_values(array_unique($ports));
    }

    private static function hash(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('expected_hash');
    }

    private static function validationFailure(string $field): LocalWebSocketRuntimeFailure
    {
        return new LocalWebSocketRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'Websocket runtime container spec is invalid.',
            meta: ['field' => $field],
        );
    }
}
