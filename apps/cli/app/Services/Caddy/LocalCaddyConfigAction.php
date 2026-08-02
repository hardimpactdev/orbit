<?php

declare(strict_types=1);

namespace App\Services\Caddy;

use App\Services\Docker\LocalDockerCommandContext;
use Orbit\Core\Caddy\CaddyfileLocalCaIntermediateLifetime;
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
        'runtime-asleep',
        'runtime-awake',
        'runtime-cold',
        'runtime-states',
        'runtime-warm',
        'start-container',
        'write-global',
        'write-site',
    ];

    private const string GLOBAL_CADDYFILE = '/etc/caddy/Caddyfile';

    private const string SITES_DIRECTORY = '/etc/caddy/sites';

    private const string DEFAULT_CONTAINER = 'orbit-caddy';

    private const string SPEC_HASH_LABEL = 'orbit.caddy.spec_hash';

    private const string RUNTIME_ACTIVITY_DIRECTORY = '/data/caddy/orbit/hibernation';

    private const string RUNTIME_MARKER_DIRECTORY = '/dev/shm/orbit/hibernation';

    public function __construct(
        private LocalDockerCommandContext $docker,
    ) {}

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
                path: $this->hostPathForContainerPath(self::GLOBAL_CADDYFILE, self::DEFAULT_CONTAINER),
                content: $this->content($payload['content'] ?? null),
            );
        }

        if ($action === 'write-site') {
            return $this->writeFile(
                path: $this->hostPathForContainerPath(
                    $this->sitePath(
                        domain: $payload['domain'] ?? null,
                        suffix: ($payload['backend'] ?? null) === true ? '.backend' : '',
                    ),
                    self::DEFAULT_CONTAINER,
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

        if ($action === 'runtime-awake') {
            return $this->markRuntimeAwake($this->runtimeKey($payload['key'] ?? null));
        }

        if ($action === 'runtime-asleep') {
            return $this->markRuntimeAsleep($this->runtimeKey($payload['key'] ?? null));
        }

        if ($action === 'runtime-cold') {
            return $this->markRuntimeCold($this->runtimeKey($payload['key'] ?? null));
        }

        if ($action === 'runtime-warm') {
            return $this->markRuntimeWarm($this->runtimeKey($payload['key'] ?? null));
        }

        if ($action === 'runtime-states') {
            return $this->runtimeStates($this->runtimeKeys($payload['keys'] ?? null));
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
    private function markRuntimeAwake(string $key): array
    {
        $activityDirectory = $this->runtimeActivityHostDirectory();
        $activity = "{$activityDirectory}/{$key}.access.log";
        $awakeMarker = self::RUNTIME_MARKER_DIRECTORY."/{$key}.awake";
        $asleepMarker = self::RUNTIME_MARKER_DIRECTORY."/{$key}.asleep";

        $this->mustRunPrivileged(
            ['install', '-d', '-m', '0755', $activityDirectory],
            'caddy_runtime.directory_failed',
        );
        $this->mustRunPrivileged(['touch', $activity], 'caddy_runtime.awake_failed');
        $this->mustRunPrivileged(['chmod', '0644', $activity], 'caddy_runtime.chmod_failed');
        $this->mustRunDocker(
            ['exec', self::DEFAULT_CONTAINER, 'mkdir', '-p', self::RUNTIME_MARKER_DIRECTORY],
            'caddy_runtime.directory_failed',
        );
        $this->mustRunDocker(
            ['exec', self::DEFAULT_CONTAINER, 'rm', '-f', $asleepMarker],
            'caddy_runtime.awake_failed',
        );
        $this->mustRunDocker(
            ['exec', self::DEFAULT_CONTAINER, 'touch', $awakeMarker],
            'caddy_runtime.awake_failed',
        );

        return [
            'key' => $key,
            'awake' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markRuntimeAsleep(string $key): array
    {
        $awakeMarker = self::RUNTIME_MARKER_DIRECTORY."/{$key}.awake";
        $asleepMarker = self::RUNTIME_MARKER_DIRECTORY."/{$key}.asleep";

        $this->mustRunDocker(
            ['exec', self::DEFAULT_CONTAINER, 'mkdir', '-p', self::RUNTIME_MARKER_DIRECTORY],
            'caddy_runtime.directory_failed',
        );
        $this->mustRunDocker(
            ['exec', self::DEFAULT_CONTAINER, 'rm', '-f', $awakeMarker],
            'caddy_runtime.asleep_failed',
        );
        $this->mustRunDocker(
            ['exec', self::DEFAULT_CONTAINER, 'touch', $asleepMarker],
            'caddy_runtime.asleep_failed',
        );

        return [
            'key' => $key,
            'awake' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markRuntimeCold(string $key): array
    {
        $activityDirectory = $this->runtimeActivityHostDirectory();
        $marker = "{$activityDirectory}/{$key}.cold";

        $this->mustRunPrivileged(
            ['install', '-d', '-m', '0755', $activityDirectory],
            'caddy_runtime.directory_failed',
        );
        $this->mustRunPrivileged(['touch', $marker], 'caddy_runtime.cold_failed');
        $this->mustRunPrivileged(['chmod', '0644', $marker], 'caddy_runtime.chmod_failed');

        return [
            'key' => $key,
            'cold' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function markRuntimeWarm(string $key): array
    {
        $marker = $this->runtimeActivityHostDirectory()."/{$key}.cold";

        $this->mustRunPrivileged(['rm', '-f', $marker], 'caddy_runtime.warm_failed');

        return [
            'key' => $key,
            'cold' => false,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function runtimeStates(array $keys): array
    {
        $activityDirectory = $this->runtimeActivityHostDirectory();
        $states = [];

        foreach ($keys as $key) {
            $awakeMarker = self::RUNTIME_MARKER_DIRECTORY."/{$key}.awake";
            $asleepMarker = self::RUNTIME_MARKER_DIRECTORY."/{$key}.asleep";
            $activity = "{$activityDirectory}/{$key}.access.log";
            $coldMarker = "{$activityDirectory}/{$key}.cold";
            $awake =
                $this->runProcess(
                    ['docker', 'exec', self::DEFAULT_CONTAINER, 'test', '-f', $awakeMarker],
                )['exit_code'] === 0;
            $hibernated =
                $this->runProcess(
                    ['docker', 'exec', self::DEFAULT_CONTAINER, 'test', '-f', $asleepMarker],
                )['exit_code'] === 0;
            $lastActivityAt = null;
            $cold = $this->runPrivilegedProcess(['test', '-f', $coldMarker])['exit_code'] === 0;

            if ($this->runPrivilegedProcess(['test', '-f', $activity])['exit_code'] === 0) {
                $lastActivityAt = $this->fileModificationTime($activity);
            }

            $states[] = [
                'key' => $key,
                'awake' => $awake,
                'hibernated' => $hibernated,
                'cold' => $cold,
                'last_activity_at' => $lastActivityAt,
            ];
        }

        return ['states' => $states];
    }

    private function fileModificationTime(string $path): ?int
    {
        foreach ([
            ['stat', '-c', '%Y', $path],
            ['stat', '-f', '%m', $path],
        ] as $command) {
            $result = $this->runPrivilegedProcess($command);
            $timestamp = trim($result['output']);

            if ($result['exit_code'] === 0 && ctype_digit($timestamp)) {
                return (int) $timestamp;
            }
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'caddy_runtime.stat_failed',
            message: 'Caddy runtime activity state could not be inspected.',
            meta: ['path' => $path],
        );
    }

    private function runtimeActivityHostDirectory(): string
    {
        return $this->hostPathForContainerPath(self::RUNTIME_ACTIVITY_DIRECTORY, self::DEFAULT_CONTAINER);
    }

    private function runtimeKey(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A(?:app-instance|workspace)-[1-9][0-9]*\z/', $value) === 1) {
            return $value;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'validation_failed',
            message: 'Caddy runtime key is invalid.',
            meta: ['field' => 'key'],
        );
    }

    /**
     * @return list<string>
     */
    private function runtimeKeys(mixed $value): array
    {
        if (! is_array($value) || $value === [] || count($value) > 200) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'validation_failed',
                message: 'Caddy runtime keys are invalid.',
                meta: ['field' => 'keys'],
            );
        }

        return array_values(array_unique(array_map(
            $this->runtimeKey(...),
            $value,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function readGlobal(): array
    {
        $path = $this->hostPathForContainerPath(self::GLOBAL_CADDYFILE, self::DEFAULT_CONTAINER);
        $exists = $this->runPrivilegedProcess(['test', '-f', $path]);

        if ($exists['exit_code'] !== 0) {
            return [
                'path' => $path,
                'content' => '',
                'exists' => false,
            ];
        }

        $read = $this->runPrivilegedProcess(['cat', $path]);

        if ($read['exit_code'] !== 0) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_config.read_failed',
                message: 'Caddy global config could not be read.',
                meta: [
                    'path' => $path,
                    'exit_code' => $read['exit_code'],
                    'output' => $read['output'],
                ],
            );
        }

        return [
            'path' => $path,
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

        $this->mustRunPrivileged(['install', '-d', '-m', '0755', $directory], 'caddy_config.directory_failed');
        $this->mustRunPrivilegedWithInput(['tee', $path], $content, 'caddy_config.write_failed');
        $this->mustRunPrivileged(['chmod', '0644', $path], 'caddy_config.chmod_failed');

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
        $sitePath = $this->hostPathForContainerPath($this->sitePath($domain, ''), $container);
        $backendSitePath = $this->hostPathForContainerPath($this->sitePath($domain, '.backend'), $container);

        $this->mustRunPrivileged([
            'rm',
            '-f',
            $sitePath,
            $backendSitePath,
            $this->hostPathForContainerPath("/etc/orbit/certs/{$domain}.crt", $container),
            $this->hostPathForContainerPath("/etc/orbit/certs/{$domain}.key", $container),
        ], 'caddy_config.remove_failed');

        $this->reload($container);

        return [
            'domain' => $domain,
            'path' => $sitePath,
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

        // Restart loops keep matching labels/network while remaining unhealthy.
        // Force replacement so doctor restore can escape a same-spec crash loop.
        if (
            $hadExistingContainer
            && (! hash_equals($spec['expected_hash'], $observedHash ?? '')
            || ! $this->containerUsesNetwork($inspection, $spec['network'])
            || $this->containerIsRestarting($inspection))
        ) {
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

        if (! $this->containerIsHealthy($this->inspectContainer($spec['name']))) {
            $this->mustRun(['docker', 'start', $spec['name']], 'caddy_container.start_failed');
            $changed = true;
            $outcome = $outcome === 'unchanged' ? 'started' : $outcome;
        }

        if ($changed) {
            $this->ensureStableRunning($spec['name']);
        }

        return [
            'container' => $spec['name'],
            'outcome' => $outcome,
            'changed' => $changed,
            'expected_hash' => $spec['expected_hash'],
        ];
    }

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function containerUsesNetwork(?array $inspection, string $network): bool
    {
        $networks = $inspection['NetworkSettings']['Networks'] ?? null;

        return is_array($networks) && array_key_exists($network, $networks);
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
            '--force',
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
            $this->ensureHostDirectory($this->accessibleHostPath($this->hostPreparationPath($directory)));
        }

        $globalCaddyfile = $this->accessibleHostPath(
            $this->hostPathFromDeclaredMounts(self::GLOBAL_CADDYFILE, $spec['mounts']),
        );
        $exists = $this->runPrivilegedProcess(['test', '-f', $globalCaddyfile]);

        if ($exists['exit_code'] !== 0) {
            $this->writeGlobalCaddyfile($globalCaddyfile, $globalConfig);

            return;
        }

        $current = $this->runPrivilegedProcess(['cat', $globalCaddyfile]);

        if ($current['exit_code'] !== 0) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_container.global_config_failed',
                message: 'Caddy global config could not be read.',
                meta: [
                    'path' => $globalCaddyfile,
                    'exit_code' => $current['exit_code'],
                    'output' => $current['output'],
                ],
            );
        }

        $desiredConfig = $this->mergedGlobalConfig(
            currentConfig: $current['output'],
            desiredConfig: $globalConfig,
        );

        if (hash_equals($desiredConfig, $current['output'])) {
            return;
        }

        $this->writeGlobalCaddyfile($globalCaddyfile, $desiredConfig);
    }

    private function writeGlobalCaddyfile(string $globalCaddyfile, string $globalConfig): void
    {
        $this->ensureHostDirectory(
            $this->accessibleHostPath($this->hostPreparationPath(dirname($globalCaddyfile))),
        );

        // Docker creates a directory at a missing file bind source; replace it
        // so tee can write the Caddyfile before the container is (re)created.
        $isDirectory = $this->runPrivilegedProcess(['test', '-d', $globalCaddyfile]);

        if ($isDirectory['exit_code'] === 0) {
            $this->mustRunPrivileged(
                ['rm', '-rf', $globalCaddyfile],
                'caddy_container.global_config_failed',
            );
        }

        $this->mustRunPrivilegedWithInput(
            ['tee', $globalCaddyfile],
            $globalConfig,
            'caddy_container.global_config_failed',
        );
        $this->mustRunPrivileged(['chmod', '0644', $globalCaddyfile], 'caddy_container.global_config_failed');
    }

    private function mergedGlobalConfig(string $currentConfig, string $desiredConfig): string
    {
        $currentConfig = rtrim(CaddyfileLocalCaIntermediateLifetime::withoutObsoleteLocalOverride($currentConfig));

        if ($currentConfig === '') {
            return $desiredConfig;
        }

        $updated = $currentConfig;

        foreach ($this->caddyNamedBlocks($desiredConfig) as $name => $block) {
            if (str_contains($updated, "({$name})")) {
                continue;
            }

            $updated .= "\n\n{$block}";
        }

        foreach ($this->caddyImportLines($desiredConfig) as $line) {
            if (str_contains($updated, $line)) {
                continue;
            }

            $updated .= "\n\n{$line}";
        }

        return rtrim($updated)."\n";
    }

    /**
     * @return array<string, string>
     */
    private function caddyNamedBlocks(string $config): array
    {
        $blocks = [];
        $lines = explode("\n", str_replace(search: ["\r\n", "\r"], replace: "\n", subject: $config));
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];
            $match = [];

            if (preg_match('/^\s*\((?P<name>[A-Za-z0-9_-]+)\)\s*\{\s*$/', $line, $match) !== 1) {
                continue;
            }

            $name = $match['name'];
            $blockLines = [$line];
            $depth = substr_count(haystack: $line, needle: '{') - substr_count(haystack: $line, needle: '}');

            while ($depth > 0 && ++$index < $count) {
                $line = $lines[$index];
                $blockLines[] = $line;
                $depth += substr_count(haystack: $line, needle: '{') - substr_count(haystack: $line, needle: '}');
            }

            $blocks[$name] = rtrim(implode("\n", $blockLines));
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function caddyImportLines(string $config): array
    {
        $matches = [];

        preg_match_all('/^\s*import\s+\S+\s*$/m', $config, $matches);

        return array_values(array_unique(array_map(
            trim(...),
            $matches[0] ?? [],
        )));
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
        $exists = $this->runPrivilegedProcess(['test', '-d', $directory]);

        if ($exists['exit_code'] === 0) {
            return;
        }

        $this->mustRunPrivileged([
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

        $pull = $this->runProcess(['docker', 'pull', $spec['image']]);

        if ($pull['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'caddy_container.image_missing',
            message: "Caddy container image {$spec['image']} is missing and could not be pulled.",
            meta: [
                'image' => $spec['image'],
                'exit_code' => $pull['exit_code'],
                'output' => $pull['output'],
            ],
        );
    }

    private function ensureNetwork(string $network): void
    {
        $inspect = $this->runProcess(['docker', 'network', 'inspect', $network]);

        if ($inspect['exit_code'] === 0) {
            return;
        }

        $create = $this->runProcess([
            'docker',
            'network',
            'create',
            '--label',
            'orbit.managed=true',
            '--label',
            'orbit.network.kind=runtime',
            $network,
        ]);

        if ($create['exit_code'] === 0 || $this->docker->networkAlreadyExists($create['output'], $network)) {
            return;
        }

        throw new LocalCaddyConfigFailure(
            errorCode: 'caddy_container.network_failed',
            message: 'Caddy config command failed.',
            meta: [
                'exit_code' => $create['exit_code'],
                'output' => $create['output'],
            ],
        );
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

    private function hostPathForContainerPath(string $containerPath, string $container): string
    {
        $inspection = $this->inspectContainer($container);

        if ($inspection === null) {
            return $this->accessibleHostPath($containerPath);
        }

        return $this->accessibleHostPath(
            $this->hostPathFromMounts($containerPath, $inspection['Mounts'] ?? null),
        );
    }

    private function accessibleHostPath(string $path): string
    {
        $prefix = getenv('ORBIT_HOST_PATH_PREFIX');

        if (! is_string($prefix) || trim($prefix) === '') {
            return $path;
        }

        $prefix = rtrim(trim($prefix), '/');

        if (
            ! str_starts_with($prefix, '/')
            || str_contains($prefix, "\0")
            || ! str_starts_with($path, '/')
            || str_contains($path, "\0")
        ) {
            throw new LocalCaddyConfigFailure(
                errorCode: 'caddy_config.host_path_invalid',
                message: 'Caddy host path mapping is invalid.',
                meta: [],
            );
        }

        if ($path === $prefix || str_starts_with($path, "{$prefix}/")) {
            return $path;
        }

        return $prefix.$path;
    }

    private function hostPathFromMounts(string $containerPath, mixed $mounts): string
    {
        if (! is_array($mounts)) {
            return $containerPath;
        }

        $bestTarget = null;
        $bestSource = null;

        foreach ($mounts as $mount) {
            if (! is_array($mount)) {
                continue;
            }

            $source = $mount['Source'] ?? null;
            $target = $mount['Destination'] ?? null;

            if (! is_string($source) || ! is_string($target) || $source === '' || $target === '') {
                continue;
            }

            if (! $this->pathIsWithinMount($containerPath, $target)) {
                continue;
            }

            if ($bestTarget === null || strlen($target) > strlen($bestTarget)) {
                $bestTarget = rtrim(string: $target, characters: '/');
                $bestSource = rtrim(string: $source, characters: '/');
            }
        }

        if ($bestTarget === null || $bestSource === null) {
            return $containerPath;
        }

        $suffix = substr($containerPath, strlen($bestTarget));

        return $bestSource.$suffix;
    }

    /**
     * @param  list<array{source: string, target: string, read_only: bool}>  $mounts
     */
    private function hostPathFromDeclaredMounts(string $containerPath, array $mounts): string
    {
        $bestTarget = null;
        $bestSource = null;

        foreach ($mounts as $mount) {
            $source = $mount['source'];
            $target = $mount['target'];

            if (! $this->pathIsWithinMount($containerPath, $target)) {
                continue;
            }

            if ($bestTarget === null || strlen($target) > strlen($bestTarget)) {
                $bestTarget = rtrim(string: $target, characters: '/');
                $bestSource = rtrim(string: $source, characters: '/');
            }
        }

        if ($bestTarget === null || $bestSource === null) {
            return $containerPath;
        }

        $suffix = substr($containerPath, strlen($bestTarget));

        return $bestSource.$suffix;
    }

    private function pathIsWithinMount(string $path, string $mountTarget): bool
    {
        $mountTarget = rtrim(string: $mountTarget, characters: '/');

        return $path === $mountTarget || str_starts_with($path, "{$mountTarget}/");
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

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function containerIsRestarting(?array $inspection): bool
    {
        $state = $inspection['State'] ?? null;

        if (! is_array($state)) {
            return false;
        }

        return ($state['Restarting'] ?? false) === true;
    }

    /**
     * @param  array<array-key, mixed>|null  $inspection
     */
    private function containerIsHealthy(?array $inspection): bool
    {
        $state = $inspection['State'] ?? null;

        if (! is_array($state)) {
            return false;
        }

        return ($state['Running'] ?? false) === true && ($state['Restarting'] ?? false) !== true;
    }

    private function ensureStableRunning(string $container): void
    {
        $deadline = microtime(true) + 3.0;
        $consecutiveHealthy = 0;

        do {
            $inspection = $this->inspectContainer($container);

            if (! $this->containerIsHealthy($inspection)) {
                $consecutiveHealthy = 0;
                usleep(150_000);

                continue;
            }

            $consecutiveHealthy++;

            if ($consecutiveHealthy >= 2) {
                return;
            }

            usleep(150_000);
        } while (microtime(true) < $deadline);

        throw new LocalCaddyConfigFailure(
            errorCode: 'caddy_container.unstable',
            message: 'Caddy container did not reach a stable running state.',
            meta: [
                'container' => $container,
                'restarting' => $this->containerIsRestarting($this->inspectContainer($container)),
            ],
        );
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
            $command[] = $this->dockerMountSpec($mount);
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
    private function dockerMountSpec(array $mount): string
    {
        $mount['source'] = $this->dockerBindSource($mount['source']);

        return $this->mountSpec($mount);
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

    private function dockerBindSource(string $source): string
    {
        $canonical = realpath($source);

        return is_string($canonical) && $canonical !== '' ? $canonical : $this->hostPreparationPath($source);
    }

    private function hostPreparationPath(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return $path;
        }

        if ($path === '/run') {
            return '/private/var/run';
        }

        if (str_starts_with($path, '/run/')) {
            return '/private/var/run/'.substr($path, strlen('/run/'));
        }

        return $path;
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

        throw new LocalCaddyConfigFailure(errorCode: $errorCode, message: 'Caddy config command failed.', meta: [
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
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

        throw new LocalCaddyConfigFailure(errorCode: $errorCode, message: 'Caddy config command failed.', meta: [
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRunPrivileged(array $command, string $errorCode): void
    {
        $result = $this->runPrivilegedProcess($command);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(errorCode: $errorCode, message: 'Caddy config command failed.', meta: [
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRunPrivilegedWithInput(array $command, string $input, string $errorCode): void
    {
        $result = $this->runPrivilegedProcess($command, $input);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(errorCode: $errorCode, message: 'Caddy config command failed.', meta: [
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function mustRunDocker(array $arguments, string $errorCode): void
    {
        $result = $this->runProcess(['docker', ...$arguments]);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalCaddyConfigFailure(errorCode: $errorCode, message: 'Caddy config command failed.', meta: [
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    private function runPrivilegedProcess(array $command, ?string $input = null): array
    {
        $result = $this->runProcess($command, $input);

        if ($result['exit_code'] === 0) {
            return $result;
        }

        return $this->runProcess(['sudo', '-n', ...$command], $input);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command, ?string $input = null): array
    {
        try {
            $process = new Process($command, null, $this->docker->environmentFor($command));
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
