<?php

declare(strict_types=1);

namespace App\Services\Processes;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalProcessLogsPayload
{
    private const array BACKENDS = ['docker', 'docker-swarm', 'systemd', 'launchd'];

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    private function __construct(
        public string $backend,
        public string $runtimeUnit,
        public int $lines,
        public bool $follow,
        public ?string $stdoutPath = null,
        public ?string $stderrPath = null,
        public ?LocalProcessLogsOperationStream $operationStream = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        $backend = self::backend($payload['backend'] ?? null);
        $instance = new self(
            backend: $backend,
            runtimeUnit: self::runtimeUnit($payload['runtime_unit'] ?? null),
            lines: self::lines($payload['lines'] ?? null),
            follow: self::follow($payload['follow'] ?? false),
            stdoutPath: self::nullablePath($payload['stdout_path'] ?? null),
            stderrPath: self::nullablePath($payload['stderr_path'] ?? null),
            operationStream: LocalProcessLogsOperationStream::from($payload['operation_stream'] ?? null),
        );

        if ($backend === 'launchd') {
            $instance->assertLaunchdPaths();
        }

        return $instance;
    }

    public function systemdServiceName(): string
    {
        $serviceName = str_ends_with($this->runtimeUnit, '.service')
            ? $this->runtimeUnit
            : "{$this->runtimeUnit}.service";

        if (preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?\.service$/', $serviceName) === 1) {
            return $serviceName;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs systemd service name is invalid.',
            meta: ['field' => 'runtime_unit'],
        );
    }

    private static function backend(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::BACKENDS, strict: true)) {
            return $value;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs backend is invalid.',
            meta: ['field' => 'backend'],
        );
    }

    private static function runtimeUnit(mixed $value): string
    {
        if (is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value) === 1) {
            return $value;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs runtime unit is invalid.',
            meta: ['field' => 'runtime_unit'],
        );
    }

    private static function lines(mixed $value): int
    {
        if (is_int($value) && $value > 0 && $value <= 10_000) {
            return $value;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs line count is invalid.',
            meta: ['field' => 'lines'],
        );
    }

    private static function follow(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs follow value is invalid.',
            meta: ['field' => 'follow'],
        );
    }

    private static function nullablePath(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs path is invalid.',
            meta: ['field' => 'stdout_path'],
        );
    }

    private function assertLaunchdPaths(): void
    {
        if (
            ! is_string($this->stdoutPath)
            || trim($this->stdoutPath) === ''
            || ! is_string($this->stderrPath)
            || trim($this->stderrPath) === ''
        ) {
            throw new LocalProcessLogsFailure(
                errorCode: 'validation_failed',
                message: 'Process logs stdout and stderr paths are required for launchd.',
                meta: ['field' => 'stdout_path'],
            );
        }

        $this->assertLaunchdPath($this->stdoutPath, 'stdout_path', '.out.log');
        $this->assertLaunchdPath($this->stderrPath, 'stderr_path', '.err.log');
    }

    private function assertLaunchdPath(string $path, string $field, string $suffix): void
    {
        $directory = self::launchdLogsDirectory();

        if (
            str_starts_with($path, "{$directory}/")
            && str_ends_with($path, $suffix)
            && basename($path) !== $suffix
        ) {
            return;
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs launchd paths must stay under the Orbit user log directory.',
            meta: [
                'field' => $field,
                'reason' => 'launchd_log_path_outside_orbit_directory',
            ],
        );
    }

    private static function launchdLogsDirectory(): string
    {
        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, characters: '/').'/Library/Logs/Orbit/processes';
        }

        return '/Users/'.get_current_user().'/Library/Logs/Orbit/processes';
    }
}
