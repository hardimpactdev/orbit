<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Database\LocalMysqlUserAction;
use App\Services\Database\LocalMysqlUserFailure;
use InvalidArgumentException;
use JsonException;

final class DatabaseAddUserCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:database-add-user {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Add a MySQL database user from an internal stdin payload';

    public function handle(LocalMysqlUserAction $users): int
    {
        if (! $this->verifyOperationToken('internal:database-add-user')) {
            return self::FAILURE;
        }

        try {
            $result = $users->run($this->readPayload());
        } catch (LocalMysqlUserFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Database add-user payload is invalid.', []);
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
            throw new InvalidArgumentException('Database add-user payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! $this->hasOnlyStringKeys($payload)) {
            throw new InvalidArgumentException('Database add-user payload must be an object.');
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
