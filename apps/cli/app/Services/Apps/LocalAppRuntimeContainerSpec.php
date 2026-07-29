<?php

declare(strict_types=1);

namespace App\Services\Apps;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class LocalAppRuntimeContainerSpec
{
    private const string APP_SPEC_HASH_LABEL = 'orbit.app.spec_hash';

    private const string WORKSPACE_SPEC_HASH_LABEL = 'orbit.workspace.spec_hash';

    /**
     * @param  array<string, string>  $environment
     * @param  list<array{source: string, target: string, read_only: bool}>  $mounts
     * @param  list<string>  $networkAliases
     * @param  array<string, string>  $extraHosts
     */
    private function __construct(
        public string $kind,
        public string $name,
        public string $image,
        public string $network,
        public string $restartPolicy,
        public string $appSlug,
        public ?string $workspaceSlug,
        public ?string $runtimeUser,
        public ?string $dockerUser,
        public string $workingDirectory,
        public array $environment,
        public array $mounts,
        public array $networkAliases,
        public array $extraHosts,
        public string $expectedHash,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value)) {
            throw self::validationFailure('spec');
        }

        $kind = self::kind($value['kind'] ?? null);

        return new self(
            kind: $kind,
            name: self::identifier($value, 'name'),
            image: self::nonEmptyString($value, 'image'),
            network: self::identifier($value, 'network'),
            restartPolicy: self::restartPolicy($value['restart_policy'] ?? null),
            appSlug: self::identifier($value, 'app_slug'),
            workspaceSlug: self::workspaceSlug($kind, $value['workspace_slug'] ?? null),
            runtimeUser: self::nullableIdentifier($value['runtime_user'] ?? null, 'runtime_user'),
            dockerUser: self::nullableDockerUser($value['docker_user'] ?? null),
            workingDirectory: self::absolutePath($value['working_directory'] ?? '/app', 'working_directory'),
            environment: self::environment($value['environment'] ?? null),
            mounts: self::mounts($value['mounts'] ?? null),
            networkAliases: self::networkAliases($value['network_aliases'] ?? []),
            extraHosts: self::extraHosts($value['extra_hosts'] ?? []),
            expectedHash: self::hash($value['expected_hash'] ?? null),
        );
    }

    public function hashLabel(): string
    {
        return $this->kind === 'workspace'
            ? self::WORKSPACE_SPEC_HASH_LABEL
            : self::APP_SPEC_HASH_LABEL;
    }

    /**
     * @return list<string>
     */
    public function runCommand(?string $resolvedDockerUser = null): array
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
            $this->network,
        ];

        $dockerUser = $resolvedDockerUser ?? $this->dockerUser;

        if ($dockerUser !== null) {
            $command[] = '--user';
            $command[] = $dockerUser;
        }

        $command[] = '--workdir';
        $command[] = $this->workingDirectory;

        if ($this->e2eDockerNetwork() === null) {
            foreach ($this->extraHosts as $host => $address) {
                $command[] = '--add-host';
                $command[] = "{$host}:{$address}";
            }
        }

        foreach ($this->networkAliases as $alias) {
            $command[] = '--network-alias';
            $command[] = $alias;
        }

        foreach ($this->labels() as $key => $value) {
            $command[] = '--label';
            $command[] = "{$key}={$value}";
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

        return $command;
    }

    private function e2eDockerNetwork(): ?string
    {
        $network = getenv('ORBIT_E2E_DOCKER_NETWORK');

        if (! is_string($network) || trim($network) === '') {
            return null;
        }

        return trim($network);
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = [
            'orbit.managed' => 'true',
            'orbit.container.kind' => $this->kind === 'workspace' ? 'workspace-runtime' : 'app-runtime',
            'orbit.app' => $this->appSlug,
            $this->hashLabel() => $this->expectedHash,
        ];

        if ($this->workspaceSlug !== null) {
            $labels['orbit.workspace'] = $this->workspaceSlug;
        }

        ksort($labels);

        return $labels;
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

    private static function kind(mixed $value): string
    {
        if ($value === 'app' || $value === 'workspace') {
            return $value;
        }

        throw self::validationFailure('kind');
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

    private static function workspaceSlug(string $kind, mixed $value): ?string
    {
        if ($kind === 'app') {
            if ($value === null) {
                return null;
            }

            throw self::validationFailure('workspace_slug');
        }

        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('workspace_slug');
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

    private static function nullableDockerUser(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^\d+:\d+$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('docker_user');
    }

    private static function absolutePath(mixed $value, string $field): string
    {
        if (! is_string($value) || ! str_starts_with($value, '/') || str_contains($value, "\0")) {
            throw self::validationFailure($field);
        }

        $segments = explode('/', $value);

        if (in_array('..', $segments, strict: true)) {
            throw self::validationFailure($field);
        }

        return $value === '/' ? $value : rtrim($value, characters: '/');
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

        /** @var mixed $source */
        $source = $value['source'] ?? null;
        /** @var mixed $target */
        $target = $value['target'] ?? null;
        /** @var mixed $readOnly */
        $readOnly = $value['read_only'] ?? false;

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
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private static function extraHosts(mixed $value): array
    {
        if (! is_array($value) || $value !== [] && array_is_list($value)) {
            throw self::validationFailure('extra_hosts');
        }

        $extraHosts = [];

        foreach ($value as $host => $address) {
            if (! is_string($host) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $host) !== 1) {
                throw self::validationFailure('extra_hosts');
            }

            if (! is_string($address) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/', $address) !== 1) {
                throw self::validationFailure('extra_hosts');
            }

            $extraHosts[$host] = $address;
        }

        ksort($extraHosts);

        return $extraHosts;
    }

    private static function hash(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1) {
            return $value;
        }

        throw self::validationFailure('expected_hash');
    }

    private static function validationFailure(string $field): LocalAppRuntimeFailure
    {
        return new LocalAppRuntimeFailure(
            errorCode: 'validation_failed',
            message: 'App runtime container spec is invalid.',
            meta: ['field' => $field],
        );
    }
}
