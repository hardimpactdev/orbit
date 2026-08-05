<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Processes\LocalDockerContainerAction;
use App\Services\Processes\LocalDockerContainerFailure;
use InvalidArgumentException;
use JsonException;

final class ProcessDockerContainerCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:process-docker-container {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run a typed Docker container lifecycle action from an internal stdin payload';

    public function handle(LocalDockerContainerAction $containers): int
    {
        if (! $this->verifyOperationToken('internal:process-docker-container')) {
            return self::FAILURE;
        }

        try {
            $result = $containers->run($this->readPayload());
        } catch (LocalDockerContainerFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Process Docker container payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result['data'], $result['meta']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Process Docker container payload must be provided on stdin.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Process Docker container payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Process Docker container payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
