<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Processes\LocalSystemdServiceAction;
use App\Services\Processes\LocalSystemdServiceFailure;
use InvalidArgumentException;
use JsonException;

final class ProcessSystemdServiceCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:process-systemd-service {action} {service} {unit-path?} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run a typed systemd process service lifecycle action';

    public function handle(LocalSystemdServiceAction $services): int
    {
        if (! $this->verifyOperationToken('internal:process-systemd-service')) {
            return self::FAILURE;
        }

        try {
            $result = $services->run(
                action: $this->argumentString('action'),
                service: $this->argumentString('service'),
                unitPath: $this->argumentStringOrNull('unit-path'),
                payload: $this->readPayloadIfPresent(),
            );
        } catch (LocalSystemdServiceFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Process systemd service payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result, []);
    }

    private function argumentString(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    private function argumentStringOrNull(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayloadIfPresent(): array
    {
        $stdin = trim($this->stdin());

        if ($stdin === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Process systemd service payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Process systemd service payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
