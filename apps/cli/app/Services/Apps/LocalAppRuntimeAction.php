<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalAppRuntimeAction
{
    private const string RUNTIME_CONFIG_PATH_PATTERN = '#^/etc/orbit/(?:apps|workspaces)/[A-Za-z0-9][A-Za-z0-9_.-]*\.ini$#';

    private const string RUNTIME_TRUST_POOL_PATH = '/etc/orbit/ca/root.crt';

    private const string USER_PATH_PATTERN = '#^/(?:home|Users)/(?<user>(?!\.{1,2}(?:/|$))[A-Za-z0-9._-]+)/(?<path>(?!\.{1,2}$)(?!\.{1,2}/)(?!.*(?:^|/)\.\.(?:/|$)).+)$#';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $action, array $payload): array
    {
        return match ($action) {
            'container:apply' => $this->applyContainer($payload),
            'container:remove' => $this->removeContainer($payload),
            'runtime-config:write' => $this->writeRuntimeConfig($payload),
            'runtime-config:remove' => $this->removeRuntimeConfig($payload),
            default => throw new LocalAppRuntimeFailure(
                errorCode: 'validation_failed',
                message: 'App runtime action is invalid.',
                meta: ['field' => 'action'],
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyContainer(array $payload): array
    {
        $spec = LocalAppRuntimeContainerSpec::from($payload['spec'] ?? null);
        $runtimeConfig = $this->runtimeConfig($payload['runtime_config'] ?? null);

        $this->ensureNetwork($spec->network);
        $inspection = $this->inspectContainer($spec->name);
        $hadExistingContainer = $inspection !== null;
        $this->ensureImageAvailable($spec, $hadExistingContainer);
        $observedHash = $this->observedSpecHash($inspection, $spec->hashLabel());

        if ($hadExistingContainer && hash_equals($spec->expectedHash, $observedHash ?? '')) {
            if ($this->isRunning($inspection)) {
                return $this->containerApplyResult($spec->name, 'unchanged', true, false);
            }

            $start = $this->runProcess(['docker', 'start', $spec->name]);

            if (! $start->isSuccessful()) {
                throw $this->containerFailure('start', $spec->name, $start, $hadExistingContainer);
            }

            return $this->containerApplyResult($spec->name, 'started', true, true);
        }

        if ($hadExistingContainer) {
            $remove = $this->runProcess(['docker', 'rm', '-f', $spec->name]);

            if (! $remove->isSuccessful()) {
                throw $this->containerFailure('remove drifted', $spec->name, $remove, true);
            }
        }

        $this->writeRuntimeConfigShape($runtimeConfig);

        $create = $this->runProcess($spec->runCommand($this->resolvedDockerUser($spec, $hadExistingContainer)));

        if (! $create->isSuccessful()) {
            throw $this->containerFailure('create', $spec->name, $create, $hadExistingContainer);
        }

        return $this->containerApplyResult(
            container: $spec->name,
            outcome: $hadExistingContainer ? 'recreated' : 'created',
            hadExistingContainer: $hadExistingContainer,
            changed: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, container: string, changed: bool}
     */
    private function removeContainer(array $payload): array
    {
        $container = $this->container($payload['container'] ?? null);
        $inspect = $this->runProcess(['docker', 'container', 'inspect', $container]);

        if (! $inspect->isSuccessful()) {
            if ($this->isDockerNoSuchContainer($inspect)) {
                return [
                    'action' => 'container:remove',
                    'container' => $container,
                    'changed' => false,
                ];
            }

            throw $this->containerFailure('inspect', $container, $inspect, false);
        }

        $remove = $this->runProcess(['docker', 'rm', '-f', $container]);

        if (! $remove->isSuccessful()) {
            throw $this->containerFailure('remove', $container, $remove, true);
        }

        return [
            'action' => 'container:remove',
            'container' => $container,
            'changed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, path: string, changed: bool}
     */
    private function writeRuntimeConfig(array $payload): array
    {
        $runtimeConfig = $this->runtimeConfig($payload['runtime_config'] ?? null);
        $this->writeRuntimeConfigShape($runtimeConfig);

        return [
            'action' => 'runtime-config:write',
            'path' => $runtimeConfig['path'],
            'changed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, path: string, changed: bool}
     */
    private function removeRuntimeConfig(array $payload): array
    {
        $path = $this->runtimeConfigPath($payload['path'] ?? null, 'runtime_config.path');
        $inspect = $this->runProcess(['sudo', '-n', 'test', '-e', $path]);

        if (! $inspect->isSuccessful()) {
            if (trim($inspect->getErrorOutput()) === '') {
                return [
                    'action' => 'runtime-config:remove',
                    'path' => $path,
                    'changed' => false,
                ];
            }

            throw $this->failure('runtime_config_probe_failed', "Failed to inspect {$path}.", [
                'path' => $path,
                'stderr' => trim($inspect->getErrorOutput()),
            ]);
        }

        $remove = $this->runProcess(['sudo', '-n', 'rm', '-f', $path]);

        if (! $remove->isSuccessful()) {
            throw $this->failure('runtime_config_remove_failed', "Failed to remove {$path}.", [
                'path' => $path,
                'stderr' => trim($remove->getErrorOutput()),
            ]);
        }

        return [
            'action' => 'runtime-config:remove',
            'path' => $path,
            'changed' => true,
        ];
    }

    private function ensureNetwork(string $network): void
    {
        $inspect = $this->runProcess(['docker', 'network', 'inspect', $network]);

        if ($inspect->isSuccessful()) {
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

        if ($create->isSuccessful()) {
            return;
        }

        throw $this->failure('runtime_network_create_failed', "Failed to create {$network} Docker network.", [
            'network' => $network,
            'stderr' => trim($create->getErrorOutput()),
        ]);
    }

    private function ensureImageAvailable(LocalAppRuntimeContainerSpec $spec, bool $hadExistingContainer): void
    {
        $inspect = $this->runProcess(['docker', 'image', 'inspect', $spec->image]);

        if ($inspect->isSuccessful()) {
            return;
        }

        if ($this->isDockerNoSuchImage($inspect)) {
            throw $this->failure(
                errorCode: 'app_runtime.image_unavailable',
                message: "FrankenPHP runtime image '{$spec->image}' is not available.",
                meta: [
                    'image' => $spec->image,
                    'php_version' => $this->phpVersionFromImage($spec->image),
                    'had_existing_container' => $hadExistingContainer,
                ],
            );
        }

        throw $this->failure(
            errorCode: 'app_runtime.image_probe_failed',
            message: "Failed to verify FrankenPHP runtime image '{$spec->image}'.",
            meta: [
                'image' => $spec->image,
                'had_existing_container' => $hadExistingContainer,
                'stderr' => trim($inspect->getErrorOutput()),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectContainer(string $container): ?array
    {
        $inspect = $this->runProcess(['docker', 'container', 'inspect', '--format', '{{json .}}', $container]);

        if (! $inspect->isSuccessful()) {
            if ($this->isDockerNoSuchContainer($inspect)) {
                return null;
            }

            throw $this->containerFailure('inspect', $container, $inspect, false);
        }

        $output = trim($inspect->getOutput());

        if ($output === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_array($decoded) && $this->hasOnlyStringKeys($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        throw $this->failure(
            'docker_container.inspect_failed',
            "Docker returned an invalid inspect payload for '{$container}'.",
            [
                'container' => $container,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $inspection
     */
    private function observedSpecHash(?array $inspection, string $hashLabel): ?string
    {
        $labels = $inspection['Config']['Labels'] ?? null;

        if (! is_array($labels)) {
            return null;
        }

        $hash = $labels[$hashLabel] ?? null;

        return is_string($hash) ? $hash : null;
    }

    /**
     * @param  array<string, mixed>|null  $inspection
     */
    private function isRunning(?array $inspection): bool
    {
        return ($inspection['State']['Running'] ?? false) === true;
    }

    private function resolvedDockerUser(LocalAppRuntimeContainerSpec $spec, bool $hadExistingContainer): ?string
    {
        if ($spec->runtimeUser === null) {
            return $spec->dockerUser;
        }

        $uid = $this->runProcess(['id', '-u', $spec->runtimeUser]);
        $gid = $this->runProcess(['id', '-g', $spec->runtimeUser]);

        if (! $uid->isSuccessful() || ! $gid->isSuccessful()) {
            throw $this->failure(
                'app_runtime.user_unavailable',
                "Runtime user '{$spec->runtimeUser}' is unavailable.",
                [
                    'runtime_user' => $spec->runtimeUser,
                    'had_existing_container' => $hadExistingContainer,
                    'stderr' => trim($uid->getErrorOutput().' '.$gid->getErrorOutput()),
                ],
            );
        }

        $uidValue = trim($uid->getOutput());
        $gidValue = trim($gid->getOutput());

        if (preg_match('/^\d+$/', $uidValue) !== 1 || preg_match('/^\d+$/', $gidValue) !== 1) {
            throw $this->failure(
                'app_runtime.user_unavailable',
                "Runtime user '{$spec->runtimeUser}' did not resolve to UID:GID.",
                [
                    'runtime_user' => $spec->runtimeUser,
                    'had_existing_container' => $hadExistingContainer,
                ],
            );
        }

        return "{$uidValue}:{$gidValue}";
    }

    /**
     * @return array{path: string, content: string, directories: list<array{path: string, mode: string, owner: string|null, group: string|null}>, trust_pool: array{path: string, content: string}|null}
     */
    private function runtimeConfig(mixed $value): array
    {
        if (! is_array($value) || ! $this->hasOnlyStringKeys($value)) {
            throw $this->failure('validation_failed', 'App runtime config payload is invalid.', [
                'field' => 'runtime_config',
            ]);
        }

        $trustPool = $value['trust_pool'] ?? null;

        return [
            'path' => $this->runtimeConfigPath($value['path'] ?? null, 'runtime_config.path'),
            'content' => $this->base64String($value['content_base64'] ?? null, 'runtime_config.content_base64'),
            'directories' => $this->directories($value['directories'] ?? []),
            'trust_pool' => $trustPool === null ? null : $this->trustPool($trustPool),
        ];
    }

    /**
     * @param  array{path: string, content: string, directories: list<array{path: string, mode: string, owner: string|null, group: string|null}>, trust_pool: array{path: string, content: string}|null}  $runtimeConfig
     */
    private function writeRuntimeConfigShape(array $runtimeConfig): void
    {
        foreach ($runtimeConfig['directories'] as $directory) {
            $this->installDirectory($directory);
        }

        if ($runtimeConfig['trust_pool'] !== null) {
            $trustPoolPath = $runtimeConfig['trust_pool']['path'];
            $this->installDirectory([
                'path' => dirname($trustPoolPath),
                'mode' => '0755',
                'owner' => null,
                'group' => null,
            ]);
            $this->writeSudoFile($trustPoolPath, $runtimeConfig['trust_pool']['content']);
            $this->mustRun(['sudo', '-n', 'chmod', '0644', $trustPoolPath], 'chmod trust pool');
        }

        $this->installDirectory([
            'path' => dirname($runtimeConfig['path']),
            'mode' => '0755',
            'owner' => null,
            'group' => null,
        ]);
        $this->writeSudoFile($runtimeConfig['path'], $runtimeConfig['content']);
        $this->mustRun(['sudo', '-n', 'chmod', '0644', $runtimeConfig['path']], 'chmod runtime config');
    }

    /**
     * @param  array{path: string, mode: string, owner: string|null, group: string|null}  $directory
     */
    private function installDirectory(array $directory): void
    {
        $command = ['sudo', '-n', 'install', '-d', '-m', $directory['mode']];

        if ($directory['owner'] !== null) {
            $command[] = '-o';
            $command[] = $directory['owner'];
        }

        if ($directory['group'] !== null) {
            $command[] = '-g';
            $command[] = $directory['group'];
        }

        $command[] = $directory['path'];

        $this->mustRun($command, "install {$directory['path']}");
    }

    private function writeSudoFile(string $path, string $content): void
    {
        $process = $this->runProcess(['sudo', '-n', 'tee', $path], $content);

        if ($process->isSuccessful()) {
            return;
        }

        throw $this->failure('runtime_config_write_failed', "Failed to write {$path}.", [
            'path' => $path,
            'stderr' => trim($process->getErrorOutput()),
        ]);
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command, string $step): void
    {
        $process = $this->runProcess($command);

        if ($process->isSuccessful()) {
            return;
        }

        throw $this->failure('runtime_config_write_failed', "Failed to {$step}.", [
            'stderr' => trim($process->getErrorOutput()),
        ]);
    }

    /**
     * @return list<array{path: string, mode: string, owner: string|null, group: string|null}>
     */
    private function directories(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->failure('validation_failed', 'App runtime config directories are invalid.', [
                'field' => 'runtime_config.directories',
            ]);
        }

        return array_map($this->directory(...), $value);
    }

    /**
     * @return array{path: string, mode: string, owner: string|null, group: string|null}
     */
    private function directory(mixed $value): array
    {
        if (! is_array($value) || ! $this->hasOnlyStringKeys($value)) {
            throw $this->failure('validation_failed', 'App runtime config directory is invalid.', [
                'field' => 'runtime_config.directories',
            ]);
        }

        $mode = $value['mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, ['0755', '0775'], strict: true)) {
            throw $this->failure('validation_failed', 'App runtime config directory mode is invalid.', [
                'field' => 'runtime_config.directories.mode',
            ]);
        }

        $owner = $this->nullableIdentifier($value['owner'] ?? null, 'runtime_config.directories.owner');
        $group = $this->nullableIdentifier($value['group'] ?? null, 'runtime_config.directories.group');

        return [
            'path' => $this->allowedDirectoryPath(
                $value['path'] ?? null,
                $mode,
                $owner,
                $group,
                'runtime_config.directories.path',
            ),
            'mode' => $mode,
            'owner' => $owner,
            'group' => $group,
        ];
    }

    /**
     * @return array{path: string, content: string}
     */
    private function trustPool(mixed $value): array
    {
        if (! is_array($value) || ! $this->hasOnlyStringKeys($value)) {
            throw $this->failure('validation_failed', 'App runtime trust pool payload is invalid.', [
                'field' => 'runtime_config.trust_pool',
            ]);
        }

        return [
            'path' => $this->runtimeTrustPoolPath($value['path'] ?? null),
            'content' => $this->base64String(
                $value['content_base64'] ?? null,
                'runtime_config.trust_pool.content_base64',
            ),
        ];
    }

    private function container(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw $this->failure('validation_failed', 'App runtime container name is invalid.', ['field' => 'container']);
    }

    private function absolutePath(mixed $value, string $field): string
    {
        if (is_string($value) && str_starts_with($value, '/') && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            return $value;
        }

        throw $this->failure('validation_failed', 'App runtime path is invalid.', ['field' => $field]);
    }

    private function runtimeConfigPath(mixed $value, string $field): string
    {
        $path = $this->absolutePath($value, $field);

        if (preg_match(self::RUNTIME_CONFIG_PATH_PATTERN, $path) === 1) {
            return $path;
        }

        throw $this->failure('validation_failed', 'App runtime path is invalid.', ['field' => $field]);
    }

    private function runtimeTrustPoolPath(mixed $value): string
    {
        $path = $this->absolutePath($value, 'runtime_config.trust_pool.path');

        if ($path === self::RUNTIME_TRUST_POOL_PATH) {
            return $path;
        }

        throw $this->failure('validation_failed', 'App runtime path is invalid.', [
            'field' => 'runtime_config.trust_pool.path',
        ]);
    }

    private function allowedDirectoryPath(
        mixed $value,
        string $mode,
        ?string $owner,
        ?string $group,
        string $field,
    ): string {
        $path = $this->absolutePath($value, $field);

        if ($this->isOrbitRuntimeConfigDirectory($path)) {
            if ($mode === '0755' && $owner === null && $group === null) {
                return $path;
            }

            throw $this->failure('validation_failed', 'App runtime config directory is invalid.', ['field' => $field]);
        }

        $user = $this->safeUserPathOwner($path);

        if ($user !== null && $mode === '0775' && $owner === $user && $group === $user) {
            return $path;
        }

        throw $this->failure('validation_failed', 'App runtime config directory is invalid.', ['field' => $field]);
    }

    private function isOrbitRuntimeConfigDirectory(string $path): bool
    {
        return in_array(
            needle: $path,
            haystack: ['/etc/orbit/apps', '/etc/orbit/workspaces', '/etc/orbit/ca'],
            strict: true,
        );
    }

    private function safeUserPathOwner(string $path): ?string
    {
        $matches = [];

        if (preg_match(self::USER_PATH_PATTERN, $path, $matches) !== 1) {
            return null;
        }

        $relativePath = $matches['path'];

        foreach (['.aws', '.config', '.gnupg', '.ssh'] as $sensitiveDirectory) {
            if ($relativePath === $sensitiveDirectory || str_starts_with($relativePath, "{$sensitiveDirectory}/")) {
                return null;
            }
        }

        if (in_array(needle: $relativePath, haystack: ['.netrc', '.npmrc', '.composer/auth.json'], strict: true)) {
            return null;
        }

        if (str_starts_with($relativePath, '.composer/auth.json/')) {
            return null;
        }

        return $matches['user'];
    }

    private function nullableIdentifier(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw $this->failure('validation_failed', 'App runtime identifier is invalid.', ['field' => $field]);
    }

    private function base64String(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw $this->failure('validation_failed', 'App runtime file payload is invalid.', ['field' => $field]);
        }

        $decoded = base64_decode($value, strict: true);

        if ($decoded === false) {
            throw $this->failure('validation_failed', 'App runtime file payload is invalid.', ['field' => $field]);
        }

        return $decoded;
    }

    /**
     * @return array{action: string, container: string, outcome: string, had_existing_container: bool, changed: bool}
     */
    private function containerApplyResult(
        string $container,
        string $outcome,
        bool $hadExistingContainer,
        bool $changed,
    ): array {
        return [
            'action' => 'container:apply',
            'container' => $container,
            'outcome' => $outcome,
            'had_existing_container' => $hadExistingContainer,
            'changed' => $changed,
        ];
    }

    private function containerFailure(
        string $step,
        string $container,
        Process $process,
        bool $hadExistingContainer,
    ): LocalAppRuntimeFailure {
        $output = trim($process->getErrorOutput().' '.$process->getOutput());
        $message = $output !== '' ? $output : 'unknown error';

        return $this->failure(
            errorCode: 'app_runtime.container_apply_failed',
            message: "Failed to {$step} {$container} container: {$message}",
            meta: [
                'container' => $container,
                'had_existing_container' => $hadExistingContainer,
                'exit_code' => $process->getExitCode(),
                'stderr' => trim($process->getErrorOutput()),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failure(string $errorCode, string $message, array $meta = []): LocalAppRuntimeFailure
    {
        return new LocalAppRuntimeFailure(
            errorCode: $errorCode,
            message: $message,
            meta: $meta,
        );
    }

    private function isDockerNoSuchContainer(Process $process): bool
    {
        $output = $process->getErrorOutput().' '.$process->getOutput();

        return preg_match('/No such (object|container)/i', $output) === 1;
    }

    private function isDockerNoSuchImage(Process $process): bool
    {
        $output = $process->getErrorOutput().' '.$process->getOutput();

        return preg_match('/No such image/i', $output) === 1;
    }

    private function phpVersionFromImage(string $image): string
    {
        if (preg_match('/php(?<version>\d+\.\d+)/', $image, $matches) === 1) {
            return $matches['version'];
        }

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function hasOnlyStringKeys(array $payload): bool
    {
        return array_all(array_keys($payload), static fn ($key) => is_string($key));
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, ?string $input = null): Process
    {
        $process = new Process($command);
        $process->setTimeout(120);

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        return $process;
    }
}
