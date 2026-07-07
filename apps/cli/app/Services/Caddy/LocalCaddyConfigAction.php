<?php

declare(strict_types=1);

namespace App\Services\Caddy;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalCaddyConfigAction
{
    private const array ACTIONS = [
        'apply-container',
        'read-global',
        'reload',
        'remove-site',
        'start-container',
        'write-global',
        'write-site',
    ];

    private const string GLOBAL_CADDYFILE = '/etc/caddy/Caddyfile';

    private const string SITES_DIRECTORY = '/etc/caddy/sites';

    private const string DEFAULT_CONTAINER = 'orbit-caddy';

    private const string SPEC_HASH_LABEL = 'orbit.caddy.spec_hash';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $action, array $payload): array
    {
        $action = $this->action($action);

        if ($action === 'read-global') {
            return $this->readGlobal();
        }

        if ($action === 'write-global') {
            return $this->writeFile(
                path: self::GLOBAL_CADDYFILE,
                content: $this->content($payload['content'] ?? null),
            );
        }

        if ($action === 'write-site') {
            return $this->writeFile(
                path: $this->sitePath(
                    domain: $payload['domain'] ?? null,
                    suffix: ($payload['backend'] ?? null) === true ? '.backend' : '',
                ),
                content: $this->content($payload['content'] ?? null),
            );
        }

        if ($action === 'remove-site') {
            return $this->removeSite(
                domain: $payload['domain'] ?? null,
                container: $this->container($payload['container'] ?? null),
            );
        }

        if ($action === 'apply-container') {
            return $this->applyContainer(
                spec: $this->containerSpec($payload['container'] ?? null),
                globalConfig: $this->globalConfig($payload['global_config'] ?? null),
            );
        }

        if ($action === 'start-container') {
            return $this->startContainer($this->container($payload['container'] ?? null));
        }

        return $this->reload($this->container($payload['container'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function readGlobal(): array
    {
        $exists = $this->runProcess(['sudo', 'test', '-f', self::GLOBAL_CADDYFILE]);

        if ($exists['exit_code'] !== 0) {
            return [
                'path' => self::GLOBAL_CADDYFILE,
                'content' => '',
                'exists' => false,
            ];
        }

        $read = $this->runProcess(['sudo', 'cat', self::GLOBAL_CADDYFILE]);

        if ($read['exit_code'] !== 0) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_config.read_failed',
                message: 'Caddy global config could not be read.',
                meta: [
                    'path' => self::GLOBAL_CADDYFILE,
                    'exit_code' => $read['exit_code'],
                    'output' => $read['output'],
                ],
            );
        }

        return [
            'path' => self::GLOBAL_CADDYFILE,
            'content' => $read['output'],
            'exists' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writeFile(string $path, string $content): array
    {
        $directory = dirname($path);

        $this->mustRun([
            'sudo',
            'install',
            '-d',
            '-m',
            '0755',
            '/etc/caddy',
            self::SITES_DIRECTORY,
        ], 'caddy_config.directory_failed');
        $this->mustRun(['sudo', 'install', '-d', '-m', '0755', $directory], 'caddy_config.directory_failed');
        $this->mustRunWithInput(['sudo', 'tee', $path], $content, 'caddy_config.write_failed');
        $this->mustRun(['sudo', 'chmod', '0644', $path], 'caddy_config.chmod_failed');

        return [
            'path' => $path,
            'hash' => hash('sha256', $content),
            'bytes' => strlen($content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function removeSite(mixed $domain, string $container): array
    {
        $domain = $this->domain($domain);

        $this->mustRun([
            'sudo',
            'rm',
            '-f',
            $this->sitePath($domain, ''),
            "/etc/orbit/certs/{$domain}.crt",
            "/etc/orbit/certs/{$domain}.key",
        ], 'caddy_config.remove_failed');

        $this->reload($container);

        return [
            'domain' => $domain,
            'path' => $this->sitePath($domain, ''),
            'container' => $container,
        ];
    }

    /**
     * @param  array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     published_ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     extra_hosts: array<string, string>,
     *     expected_hash: string,
     * }  $spec
     * @return array<string, mixed>
     */
    private function applyContainer(array $spec, string $globalConfig): array
    {
        $this->prepareContainerHostConfig($spec, $globalConfig);
        $this->ensureImageExists($spec);
        $this->ensureNetwork($spec['network']);

        $inspection = $this->inspectContainer($spec['name']);
        $hadExistingContainer = $inspection !== null;
        $observedHash = $this->observedSpecHash($inspection);
        $changed = false;
        $outcome = 'unchanged';

        if ($hadExistingContainer && ! hash_equals($spec['expected_hash'], $observedHash ?? '')) {
            $this->mustRun(['docker', 'rm', '-f', $spec['name']], 'caddy_container.remove_failed');
            $inspection = null;
            $changed = true;
            $outcome = 'recreated';
        }

        if ($inspection === null) {
            $this->mustRun($this->containerRunCommand($spec), 'caddy_container.create_failed');
            $changed = true;
            $outcome = $hadExistingContainer ? 'recreated' : 'created';
        }

        if (! $this->containerIsRunning($spec['name'])) {
            $this->mustRun(['docker', 'start', $spec['name']], 'caddy_container.start_failed');
            $changed = true;
            $outcome = $outcome === 'unchanged' ? 'started' : $outcome;
        }

        return [
            'container' => $spec['name'],
            'outcome' => $outcome,
            'changed' => $changed,
            'expected_hash' => $spec['expected_hash'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function startContainer(string $container): array
    {
        $this->mustRun(['docker', 'start', $container], 'caddy_container.start_failed');

        return [
            'container' => $container,
            'changed' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reload(string $container): array
    {
        $result = $this->runProcess([
            'docker',
            'exec',
            $container,
            'caddy',
            'reload',
            '--config',
            self::GLOBAL_CADDYFILE,
            '--adapter',
            'caddyfile',
            '--address',
            'localhost:2019',
        ]);

        if ($result['exit_code'] !== 0) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_config.reload_failed',
                message: 'Caddy config reload failed.',
                meta: [
                    'container' => $container,
                    'exit_code' => $result['exit_code'],
                    'output' => $result['output'],
                ],
            );
        }

        return [
            'container' => $container,
            'exit_code' => $result['exit_code'],
        ];
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy config action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function content(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy config content must be a string.',
            meta: ['field' => 'content'],
        );
    }

    private function sitePath(mixed $domain, string $suffix): string
    {
        return self::SITES_DIRECTORY."/{$this->domain($domain)}{$suffix}.caddy";
    }

    private function domain(mixed $domain): string
    {
        if (! is_string($domain) || preg_match('/\A(?:\*\.)?[A-Za-z0-9][A-Za-z0-9._-]*\z/', $domain) !== 1) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'validation_failed',
                message: 'Caddy site domain is invalid.',
                meta: ['field' => 'domain'],
            );
        }

        return $domain;
    }

    private function container(mixed $value): string
    {
        if ($value === null) {
            return self::DEFAULT_CONTAINER;
        }

        if (is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/', $value) === 1) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy container name is invalid.',
            meta: ['field' => 'container'],
        );
    }

    private function globalConfig(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy global config must be a non-empty string.',
            meta: ['field' => 'global_config'],
        );
    }

    /**
     * @return array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     published_ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     extra_hosts: array<string, string>,
     *     expected_hash: string,
     * }
     */
    private function containerSpec(mixed $value): array
    {
        if (! is_array($value)) {
            throw $this->validationFailure('container');
        }

        return [
            'name' => $this->container($value['name'] ?? null),
            'image' => $this->nonEmptyString($value['image'] ?? null, 'container.image'),
            'network' => $this->identifier($value['network'] ?? null, 'container.network'),
            'restart_policy' => $this->restartPolicy($value['restart_policy'] ?? null),
            'published_ports' => $this->stringList($value['published_ports'] ?? [], 'container.published_ports'),
            'mounts' => $this->mounts($value['mounts'] ?? null),
            'network_aliases' => $this->stringList($value['network_aliases'] ?? [], 'container.network_aliases'),
            'extra_hosts' => $this->stringMap($value['extra_hosts'] ?? [], 'container.extra_hosts'),
            'expected_hash' => $this->hash($value['expected_hash'] ?? null),
        ];
    }

    /**
     * @param  array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     published_ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     extra_hosts: array<string, string>,
     *     expected_hash: string,
     * }  $spec
     */
    private function prepareContainerHostConfig(array $spec, string $globalConfig): void
    {
        $directories = $this->hostMountDirectories($spec['mounts']);

        foreach ($directories as $directory) {
            $this->ensureHostDirectory($directory);
        }

        $exists = $this->runProcess(['sudo', 'test', '-f', self::GLOBAL_CADDYFILE]);

        if ($exists['exit_code'] === 0) {
            return;
        }

        $this->mustRunWithInput(
            ['sudo', 'tee', self::GLOBAL_CADDYFILE],
            $globalConfig,
            'caddy_container.global_config_failed',
        );
        $this->mustRun(['sudo', 'chmod', '0644', self::GLOBAL_CADDYFILE], 'caddy_container.global_config_failed');
    }

    /**
     * @param  list<array{source: string, target: string, read_only: bool}>  $mounts
     * @return list<string>
     */
    private function hostMountDirectories(array $mounts): array
    {
        $directories = [];

        foreach ($mounts as $mount) {
            $source = $mount['source'];
            $candidate = str_ends_with($source, 'Caddyfile') ? dirname($source) : $source;

            if ($candidate === '' || $candidate === '/') {
                continue;
            }

            if (! in_array($candidate, $directories, strict: true)) {
                $directories[] = $candidate;
            }
        }

        return $directories;
    }

    private function ensureHostDirectory(string $directory): void
    {
        $exists = $this->runProcess(['sudo', 'test', '-d', $directory]);

        if ($exists['exit_code'] === 0) {
            return;
        }

        $this->mustRun([
            'sudo',
            'install',
            '-d',
            '-m',
            '0755',
            $directory,
        ], 'caddy_container.host_config_failed');
    }

    /**
     * @param  array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     published_ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     extra_hosts: array<string, string>,
     *     expected_hash: string,
     * }  $spec
     */
    private function ensureImageExists(array $spec): void
    {
        $result = $this->runProcess(['docker', 'image', 'inspect', $spec['image']]);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'caddy_container.image_missing',
            message: "Caddy container image {$spec['image']} is missing.",
            meta: [
                'image' => $spec['image'],
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    private function ensureNetwork(string $network): void
    {
        $inspect = $this->runProcess(['docker', 'network', 'inspect', $network]);

        if ($inspect['exit_code'] === 0) {
            return;
        }

        $this->mustRun([
            'docker',
            'network',
            'create',
            '--label',
            'orbit.managed=true',
            '--label',
            'orbit.network.kind=runtime',
            $network,
        ], 'caddy_container.network_failed');
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function inspectContainer(string $container): ?array
    {
        $inspect = $this->runProcess(['docker', 'container', 'inspect', '--format', '{{json .}}', $container]);

        if ($inspect['exit_code'] !== 0) {
            return null;
        }

        $output = trim($inspect['output']);

        if ($output === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_array($decoded)) {
            return $decoded;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'caddy_container.inspect_failed',
            message: 'Docker returned an invalid Caddy container inspect payload.',
            meta: ['container' => $container],
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function observedSpecHash(?array $inspection): ?string
    {
        $config = $inspection['Config'] ?? null;

        if (! is_array($config)) {
            return null;
        }

        $labels = $config['Labels'] ?? null;

        if (! is_array($labels)) {
            return null;
        }

        $hash = $labels[self::SPEC_HASH_LABEL] ?? null;

        return is_string($hash) ? $hash : null;
    }

    private function containerIsRunning(string $container): bool
    {
        $inspect = $this->runProcess(['docker', 'container', 'inspect', '--format', '{{.State.Running}}', $container]);

        return $inspect['exit_code'] === 0 && trim($inspect['output']) === 'true';
    }

    /**
     * @param  array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     published_ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     extra_hosts: array<string, string>,
     *     expected_hash: string,
     * }  $spec
     * @return list<string>
     */
    private function containerRunCommand(array $spec): array
    {
        $command = [
            'docker',
            'run',
            '-d',
            '--pull',
            'never',
            '--name',
            $spec['name'],
            '--restart',
            $spec['restart_policy'],
            '--network',
            $spec['network'],
        ];

        foreach ($spec['published_ports'] as $port) {
            $command[] = '--publish';
            $command[] = $port;
        }

        foreach ($spec['extra_hosts'] as $host => $address) {
            $command[] = '--add-host';
            $command[] = "{$host}:{$address}";
        }

        foreach ($spec['network_aliases'] as $alias) {
            $command[] = '--network-alias';
            $command[] = $alias;
        }

        foreach ($this->containerLabels($spec['expected_hash']) as $key => $value) {
            $command[] = '--label';
            $command[] = "{$key}={$value}";
        }

        foreach ($spec['mounts'] as $mount) {
            $command[] = '--mount';
            $command[] = $this->mountSpec($mount);
        }

        $command[] = $spec['image'];

        return $command;
    }

    /**
     * @return array<string, string>
     */
    private function containerLabels(string $expectedHash): array
    {
        return [
            'orbit.container.kind' => 'caddy',
            'orbit.managed' => 'true',
            self::SPEC_HASH_LABEL => $expectedHash,
        ];
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

    private function nonEmptyString(mixed $value, string $field): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        throw $this->validationFailure($field);
    }

    private function identifier(mixed $value, string $field): string
    {
        if (is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/', $value) === 1) {
            return $value;
        }

        throw $this->validationFailure($field);
    }

    private function restartPolicy(mixed $value): string
    {
        if (is_string($value) && in_array($value, ['always', 'no', 'on-failure', 'unless-stopped'], strict: true)) {
            return $value;
        }

        throw $this->validationFailure('container.restart_policy');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw $this->validationFailure($field);
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw $this->validationFailure($field);
            }

            $strings[] = trim($item);
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw $this->validationFailure($field);
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item) || trim($key) === '' || trim($item) === '') {
                throw $this->validationFailure($field);
            }

            $map[trim($key)] = trim($item);
        }

        ksort($map);

        return $map;
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function mounts(mixed $value): array
    {
        if (! is_array($value)) {
            throw $this->validationFailure('container.mounts');
        }

        return array_map(function (mixed $mount): array {
            if (! is_array($mount)) {
                throw $this->validationFailure('container.mounts');
            }

            $source = $this->absolutePath($mount['source'] ?? null, 'container.mounts.source');
            $target = $this->absolutePath($mount['target'] ?? null, 'container.mounts.target');
            $readOnly = $mount['read_only'] ?? false;

            if (! is_bool($readOnly)) {
                throw $this->validationFailure('container.mounts.read_only');
            }

            return [
                'source' => $source,
                'target' => $target,
                'read_only' => $readOnly,
            ];
        }, array_values($value));
    }

    private function absolutePath(mixed $value, string $field): string
    {
        if (is_string($value) && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return $value;
        }

        throw $this->validationFailure($field);
    }

    private function hash(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1) {
            return $value;
        }

        throw $this->validationFailure('container.expected_hash');
    }

    private function validationFailure(string $field): LocalCaddyConfigFailure
    {
        return new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy config payload is invalid.',
            meta: ['field' => $field],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command, string $errorCode): void
    {
        $result = $this->runProcess($command);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: $errorCode,
            message: 'Caddy config command failed.',
            meta: [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRunWithInput(array $command, string $input, string $errorCode): void
    {
        $result = $this->runProcess($command, $input);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: $errorCode,
            message: 'Caddy config command failed.',
            meta: [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command, ?string $input = null): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout(30);

            if ($input !== null) {
                $process->setInput($input);
            }

            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => trim($process->getOutput().$process->getErrorOutput()),
            ];
        } catch (ProcessStartFailedException $exception) {
            return [
                'exit_code' => 127,
                'output' => $exception->getMessage(),
            ];
        }
    }
}
