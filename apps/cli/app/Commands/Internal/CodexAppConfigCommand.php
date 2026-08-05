<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\CodexApp\LocalCodexAppConfigAction;
use App\Services\CodexApp\LocalCodexAppConfigFailure;
use InvalidArgumentException;
use JsonException;

final class CodexAppConfigCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:codex-app-config {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Read, write, or apply the local Codex App config';

    public function handle(LocalCodexAppConfigAction $config): int
    {
        if (! $this->verifyOperationToken('internal:codex-app-config')) {
            return self::FAILURE;
        }

        try {
            $result = $config->run($this->readPayload());
        } catch (LocalCodexAppConfigFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Codex App config payload is invalid.', []);
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
            throw new InvalidArgumentException('Codex App config payload must be provided on stdin.');
        }

        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Codex App config payload must be an object.');
        }

        $normalized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
