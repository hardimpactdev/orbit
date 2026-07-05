<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Processes\LocalProcessLogsAction;
use App\Services\Processes\LocalProcessLogsFailure;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Input\StreamableInputInterface;

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
            $result = $logs->read($this->readPayload());
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

    private function stdin(): string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;

        if (is_resource($stream)) {
            return (string) stream_get_contents($stream);
        }

        return (string) stream_get_contents(STDIN);
    }
}
