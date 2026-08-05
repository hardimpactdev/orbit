<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\ApplicationLogs\LocalApplicationLogAction;
use App\Services\ApplicationLogs\LocalApplicationLogFailure;
use InvalidArgumentException;
use JsonException;

final class ApplicationLogCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:application-log {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Read or follow a fixed Laravel application log path from a typed internal stdin payload';

    public function handle(LocalApplicationLogAction $logs): int
    {
        if (! $this->verifyOperationToken('internal:application-log')) {
            return self::FAILURE;
        }

        try {
            $payload = $this->readPayload();

            if (($payload['follow'] ?? false) === true) {
                return $logs->stream($payload, function (string $output): void {
                    $this->output->write($output);
                }) === 0
                    ? self::SUCCESS
                    : self::FAILURE;
            }

            $result = $logs->read($payload);
        } catch (LocalApplicationLogFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Application log payload is invalid.', []);
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
            throw new InvalidArgumentException('Application log payload must be provided on stdin.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Application log payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Application log payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
