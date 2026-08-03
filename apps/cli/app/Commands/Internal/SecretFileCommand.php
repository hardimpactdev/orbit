<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Secrets\LocalSecretFileAction;
use App\Services\Secrets\LocalSecretFileFailure;
use InvalidArgumentException;
use JsonException;

final class SecretFileCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:secret-file {action} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Stage or remove a temporary secret file through typed local file operations';

    public function handle(LocalSecretFileAction $secretFiles): int
    {
        if (! $this->verifyOperationToken('internal:secret-file')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($secretFiles->run(
                action: $this->argument('action'),
                payload: $this->readPayload(),
            ));
        } catch (LocalSecretFileFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Secret file payload is invalid.', []);
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
            throw new InvalidArgumentException('Secret file payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
