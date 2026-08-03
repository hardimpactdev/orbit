<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Schedules\LocalScheduleRunAction;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ScheduleRunCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:schedule:run {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run a schedule execution payload through the local executor';

    public function handle(LocalScheduleRunAction $scheduleRun): int
    {
        if (! $this->verifyOperationToken('internal:schedule:run')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($scheduleRun->run($this->readPayload()));
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Schedule run payload is invalid.', []);
        } catch (Throwable $throwable) {
            return $this->renderFailure('schedule_run_failed', $throwable->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Schedule run payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! array_all(array_keys($payload), static fn ($key) => is_string($key))) {
            throw new InvalidArgumentException('Schedule run payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
