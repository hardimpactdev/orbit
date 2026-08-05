<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppRuntimeAction;
use App\Services\Apps\LocalAppRuntimeFailure;
use InvalidArgumentException;
use JsonException;

final class AppRuntimeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-runtime-container {action} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Converge local app/workspace runtime containers through fixed argv operations';

    public function handle(LocalAppRuntimeAction $action): int
    {
        if (! $this->verifyOperationToken('internal:app-runtime-container')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->run($this->argument('action'), $this->readPayload()));
        } catch (LocalAppRuntimeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'App runtime payload is invalid.', []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            return [];
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('App runtime payload must be an object.');
        }

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('App runtime payload keys must be strings.');
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
