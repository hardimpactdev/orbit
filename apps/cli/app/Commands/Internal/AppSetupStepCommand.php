<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppSetupStepAction;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class AppSetupStepCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-setup-step {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run one app setup command through the local executor';

    public function handle(LocalAppSetupStepAction $setupStep): int
    {
        if (! $this->verifyOperationToken('internal:app-setup-step')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($setupStep->run($this->readPayload()));
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'App setup step payload is invalid.', []);
        } catch (Throwable $throwable) {
            return $this->renderFailure('app_setup_step_failed', $throwable->getMessage(), []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('App setup step payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload) || ! array_all(array_keys($payload), static fn ($key) => is_string($key))) {
            throw new InvalidArgumentException('App setup step payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
