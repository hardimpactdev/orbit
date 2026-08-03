<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Processes\LocalDockerSwarmServiceAction;
use App\Services\Processes\LocalDockerSwarmServiceFailure;
use InvalidArgumentException;
use JsonException;

final class ProcessDockerSwarmServiceCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:process-docker-swarm-service {action} {service} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run a typed Docker Swarm service lifecycle action';

    public function handle(LocalDockerSwarmServiceAction $services): int
    {
        if (! $this->verifyOperationToken('internal:process-docker-swarm-service')) {
            return self::FAILURE;
        }

        try {
            $result = $services->run(
                action: $this->argumentString('action'),
                service: $this->argumentString('service'),
                payload: $this->readPayloadIfPresent(),
            );
        } catch (LocalDockerSwarmServiceFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Process Docker Swarm service payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result, []);
    }

    private function argumentString(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
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
            throw new InvalidArgumentException('Process Docker Swarm service payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Process Docker Swarm service payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
