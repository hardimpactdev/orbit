<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Caddy\LocalCaddyConfigAction;
use App\Services\Caddy\LocalCaddyConfigFailure;
use InvalidArgumentException;
use JsonException;

final class CaddyConfigCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:caddy-config {action} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Read, write, or reload local Caddy configuration through fixed operations';

    public function handle(LocalCaddyConfigAction $caddy): int
    {
        if (! $this->verifyOperationToken('internal:caddy-config')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($caddy->run(
                action: $this->argument('action'),
                payload: $this->readPayload(),
            ));
        } catch (LocalCaddyConfigFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Caddy config payload is invalid.', []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Caddy config payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Caddy config payload must be an object.');
        }

        $normalized = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
