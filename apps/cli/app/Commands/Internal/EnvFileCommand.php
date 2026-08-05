<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\EnvFiles\LocalEnvFileAction;
use App\Services\EnvFiles\LocalEnvFileFailure;
use InvalidArgumentException;
use JsonException;

final class EnvFileCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:env-file {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Read or write a typed internal env-file payload';

    public function handle(LocalEnvFileAction $envFiles): int
    {
        if (! $this->verifyOperationToken('internal:env-file')) {
            return self::FAILURE;
        }

        try {
            $result = $envFiles->run($this->readPayload());
        } catch (LocalEnvFileFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Env file payload is invalid.', []);
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
            throw new InvalidArgumentException('Env file payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! $this->hasOnlyStringKeys($payload)) {
            throw new InvalidArgumentException('Env file payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function hasOnlyStringKeys(array $payload): bool
    {
        return array_all(array_keys($payload), fn ($key) => is_string($key));
    }
}
