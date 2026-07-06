<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalAppIntrospectProbe
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function probe(array $payload): array
    {
        $input = $this->input($payload);
        $pathExists = is_dir($input['path']);
        $rootPath = $this->rootPath($input['path'], $input['document_root']);
        $dockerAvailable = $this->successful(['docker', '--version'], 10);

        $snapshot = [
            'name' => $input['name'],
            'path_exists' => $pathExists,
            'root_exists' => is_dir($rootPath),
            'root_inside_path' => $this->isInside($rootPath, $input['path']),
            'docker_available' => $dockerAvailable,
            'container_exists' => false,
            'container_spec_matches' => false,
            'container_running' => false,
            'system_user_exists' =>
                $input['runtime_user'] !== '' && $this->successful(['id', '-u', $input['runtime_user']], 10),
            'fs_permissions_ok' =>
                $pathExists
                    && $input['runtime_user'] !== ''
                    && $this->ownedBy($input['path'], $input['runtime_user'])
                    && $this->notWorldWritable($input['path']),
            'runtime_config_exists' => false,
            'runtime_config_matches' => $input['runtime_kind'] === 'static',
            'runtime_image_available' => $input['runtime_kind'] === 'static',
            'runtime_image_probe_failed' => false,
        ];

        if ($input['runtime_kind'] === 'php') {
            if ($dockerAvailable) {
                $snapshot = $this->withContainerState($snapshot, $input);
            }

            $snapshot = $this->withRuntimeConfigState($snapshot, $input);

            if ($dockerAvailable) {
                $snapshot = $this->withRuntimeImageState($snapshot, $input);
            }
        }

        return [
            'data' => [
                'snapshot' => $snapshot,
            ],
            'meta' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     name: string,
     *     path: string,
     *     document_root: string,
     *     runtime_kind: string,
     *     runtime_user: string,
     *     runtime_container_name: string,
     *     expected_spec_hash: string,
     *     runtime_config_path: string,
     *     expected_runtime_config_hash: string,
     *     expected_runtime_image: string,
     * }
     */
    private function input(array $payload): array
    {
        return [
            'name' => $this->requiredString($payload, 'name'),
            'path' => $this->absolutePath($payload, 'path'),
            'document_root' => $this->requiredString($payload, 'document_root'),
            'runtime_kind' => $this->runtimeKind($payload),
            'runtime_user' => $this->optionalString($payload, 'runtime_user'),
            'runtime_container_name' => $this->optionalString($payload, 'runtime_container_name'),
            'expected_spec_hash' => $this->optionalString($payload, 'expected_spec_hash'),
            'runtime_config_path' => $this->optionalAbsolutePath($payload, 'runtime_config_path'),
            'expected_runtime_config_hash' => $this->optionalString($payload, 'expected_runtime_config_hash'),
            'expected_runtime_image' => $this->optionalString($payload, 'expected_runtime_image'),
        ];
    }

    /**
     * @param  array<string, bool|string>  $snapshot
     * @param  array<string, string>  $input
     * @return array<string, bool|string>
     */
    private function withContainerState(array $snapshot, array $input): array
    {
        if ($input['runtime_container_name'] === '') {
            return $snapshot;
        }

        if (! $this->successful(['docker', 'container', 'inspect', $input['runtime_container_name']], 20)) {
            return $snapshot;
        }

        $snapshot['container_exists'] = true;
        $observedHash = trim(
            $this->run([
                'docker',
                'container',
                'inspect',
                '--format',
                '{{index .Config.Labels "orbit.app.spec_hash"}}',
                $input['runtime_container_name'],
            ], 20)->getOutput(),
        );
        $snapshot['container_spec_matches'] =
            $input['expected_spec_hash'] !== '' && $observedHash === $input['expected_spec_hash'];

        $running = trim(
            $this->run([
                'docker',
                'container',
                'inspect',
                '--format',
                '{{.State.Running}}',
                $input['runtime_container_name'],
            ], 20)->getOutput(),
        );
        $snapshot['container_running'] = $running === 'true';

        return $snapshot;
    }

    /**
     * @param  array<string, bool|string>  $snapshot
     * @param  array<string, string>  $input
     * @return array<string, bool|string>
     */
    private function withRuntimeConfigState(array $snapshot, array $input): array
    {
        if ($input['runtime_config_path'] === '') {
            return $snapshot;
        }

        $exists = $this->successful(['test', '-e', $input['runtime_config_path']], 10);
        $snapshot['runtime_config_exists'] = $exists;

        if (! $exists) {
            return $snapshot;
        }

        $hash = hash_file('sha256', $input['runtime_config_path']);
        $hash = is_string($hash) ? $hash : '';
        $snapshot['runtime_config_matches'] =
            $input['expected_runtime_config_hash'] !== '' && $hash === $input['expected_runtime_config_hash'];

        return $snapshot;
    }

    /**
     * @param  array<string, bool|string>  $snapshot
     * @param  array<string, string>  $input
     * @return array<string, bool|string>
     */
    private function withRuntimeImageState(array $snapshot, array $input): array
    {
        if ($input['expected_runtime_image'] === '') {
            return $snapshot;
        }

        $process = $this->run(['docker', 'image', 'inspect', $input['expected_runtime_image']], 20);

        if ($process->isSuccessful()) {
            $snapshot['runtime_image_available'] = true;

            return $snapshot;
        }

        $snapshot['runtime_image_probe_failed'] = ! str_contains(
            strtolower($process->getErrorOutput()),
            'no such image',
        );

        return $snapshot;
    }

    private function rootPath(string $path, string $documentRoot): string
    {
        $root = trim($documentRoot, characters: '/');

        if ($root === '' || $root === '.') {
            return $path;
        }

        return rtrim($path, characters: '/')."/{$root}";
    }

    private function isInside(string $path, string $parent): bool
    {
        $parent = rtrim($parent, characters: '/');

        return $path === $parent || str_starts_with($path, "{$parent}/");
    }

    private function ownedBy(string $path, string $user): bool
    {
        $linux = $this->run(['stat', '-c', '%U', $path], 10);

        if ($linux->isSuccessful() && trim($linux->getOutput()) === $user) {
            return true;
        }

        $darwin = $this->run(['stat', '-f', '%Su', $path], 10);

        return $darwin->isSuccessful() && trim($darwin->getOutput()) === $user;
    }

    private function notWorldWritable(string $path): bool
    {
        $process = $this->run(['find', $path, '-maxdepth', '0', '!', '-perm', '/022', '-print'], 10);

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    /**
     * @param  list<string>  $command
     */
    private function successful(array $command, int $timeout): bool
    {
        return $this->run($command, $timeout)->isSuccessful();
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        /** @var mixed $value */
        $value = $payload[$key] ?? null;

        if (is_string($value) && $value !== '' && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new LocalAppIntrospectProbeFailure('validation_failed', 'App introspection payload is invalid.', [
            'field' => $key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): string
    {
        /** @var mixed $value */
        $value = $payload[$key] ?? '';

        if (is_string($value) && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new LocalAppIntrospectProbeFailure('validation_failed', 'App introspection payload is invalid.', [
            'field' => $key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function absolutePath(array $payload, string $key): string
    {
        $value = $this->requiredString($payload, $key);

        if (str_starts_with($value, '/')) {
            return $value;
        }

        throw new LocalAppIntrospectProbeFailure('validation_failed', 'App introspection path must be absolute.', [
            'field' => $key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalAbsolutePath(array $payload, string $key): string
    {
        $value = $this->optionalString($payload, $key);

        if ($value === '' || str_starts_with($value, '/')) {
            return $value;
        }

        throw new LocalAppIntrospectProbeFailure('validation_failed', 'App introspection path must be absolute.', [
            'field' => $key,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runtimeKind(array $payload): string
    {
        $kind = $this->requiredString($payload, 'runtime_kind');

        if (in_array($kind, ['php', 'static'], strict: true)) {
            return $kind;
        }

        throw new LocalAppIntrospectProbeFailure('validation_failed', 'App runtime kind is invalid.', [
            'field' => 'runtime_kind',
        ]);
    }
}
