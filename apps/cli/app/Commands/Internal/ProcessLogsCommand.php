<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Processes\LocalProcessLogsAction;
use App\Services\Processes\LocalProcessLogsFailure;
use InvalidArgumentException;
use JsonException;

final class ProcessLogsCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:process-logs {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Read process logs from a typed internal stdin payload';

    public function handle(LocalProcessLogsAction $logs): int
    {
        if (! $this->verifyOperationToken('internal:process-logs')) {
            return self::FAILURE;
        }

        try {
            $payload = $this->readPayload();

            if (($payload['follow'] ?? false) === true) {
                return $logs->stream($payload, function (string $output): void {
                    $this->output->write($output);
                }) === self::SUCCESS
                    ? self::SUCCESS
                    : self::FAILURE;
            }

            $result = $logs->read($payload);
        } catch (LocalProcessLogsFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Process logs payload is invalid.', []);
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
            throw new InvalidArgumentException('Process logs payload must be provided on stdin.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Process logs payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Process logs payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
