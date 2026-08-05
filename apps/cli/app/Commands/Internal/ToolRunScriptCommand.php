<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Tools\LocalToolRunScriptAction;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ToolRunScriptCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:tool:run-script {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run a catalog tool script through the local executor';

    public function handle(LocalToolRunScriptAction $toolRunScript): int
    {
        if (! $this->verifyOperationToken('internal:tool:run-script')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($toolRunScript->run($this->readPayload()));
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Tool run payload is invalid.', []);
        } catch (Throwable $throwable) {
            return $this->renderFailure('tool_run_failed', $throwable->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Tool run payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! array_all(array_keys($payload), static fn ($key) => is_string($key))) {
            throw new InvalidArgumentException('Tool run payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
