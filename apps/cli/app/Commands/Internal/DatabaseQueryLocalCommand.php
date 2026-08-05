<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Database\LocalDatabaseQueryAction;
use App\Services\Database\LocalDatabaseQueryFailure;
use InvalidArgumentException;
use JsonException;

final class DatabaseQueryLocalCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:database-query-local {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Execute a local database query from an internal stdin payload';

    public function handle(LocalDatabaseQueryAction $query): int
    {
        if (! $this->verifyOperationToken('internal:database-query-local')) {
            return self::FAILURE;
        }

        try {
            $payload = $this->readPayload();
            $result = $query->run($payload);
        } catch (LocalDatabaseQueryFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Database query payload is invalid.', []);
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
            throw new InvalidArgumentException('Connection payload must be provided on stdin.');
        }

        $payload = json_decode($stdin, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Connection payload must be an object.');
        }

        return $payload;
    }
}
