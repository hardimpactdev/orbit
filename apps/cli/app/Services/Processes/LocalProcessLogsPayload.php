<?php

declare(strict_types=1);

namespace App\Services\Processes;

final readonly class LocalProcessLogsPayload
{
    private const array BACKENDS = ['docker', 'docker-swarm', 'systemd'];

    private function __construct(
        public string $backend,
        public string $runtimeUnit,
        public int $lines,
        public bool $follow,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        return new self(
            backend: self::backend($payload['backend'] ?? null),
            runtimeUnit: self::runtimeUnit($payload['runtime_unit'] ?? null),
            lines: self::lines($payload['lines'] ?? null),
            follow: self::follow($payload['follow'] ?? false),
        );
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
}
