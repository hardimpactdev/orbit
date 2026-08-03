<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Deploy\LocalDeployRunStepAction;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class DeployRunStepCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:deploy:run-step {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run one deployment command through the local executor';

    public function handle(LocalDeployRunStepAction $runStep): int
    {
        if (! $this->verifyOperationToken('internal:deploy:run-step')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($runStep->run($this->readPayload()));
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Deploy run step payload is invalid.', []);
        } catch (Throwable $throwable) {
            return $this->renderFailure('deploy_run_step_failed', $throwable->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Deploy run step payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! array_all(array_keys($payload), static fn ($key) => is_string($key))) {
            throw new InvalidArgumentException('Deploy run step payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
