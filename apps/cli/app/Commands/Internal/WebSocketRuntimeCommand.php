<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\WebSockets\LocalWebSocketRuntimeAction;
use App\Services\WebSockets\LocalWebSocketRuntimeFailure;
use InvalidArgumentException;
use JsonException;

final class WebSocketRuntimeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:websocket-runtime {action} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Inspect and prepare the local websocket runtime through fixed argv operations';

    public function handle(LocalWebSocketRuntimeAction $action): int
    {
        if (! $this->verifyOperationToken('internal:websocket-runtime')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->run($this->argument('action'), $this->readPayload()));
        } catch (LocalWebSocketRuntimeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Websocket runtime payload is invalid.', []);
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
            throw new InvalidArgumentException('Websocket runtime payload must be an object.');
        }

        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Websocket runtime payload keys must be strings.');
            }
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
