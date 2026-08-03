<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Convergence\LocalManagedFileAction;
use App\Services\Convergence\LocalManagedFileFailure;
use InvalidArgumentException;
use JsonException;

final class ManagedFileCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:managed-file {action} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe or write a typed internal managed file payload';

    public function handle(LocalManagedFileAction $managedFiles): int
    {
        if (! $this->verifyOperationToken('internal:managed-file')) {
            return self::FAILURE;
        }

        try {
            $result = $managedFiles->run(
                action: $this->argument('action'),
                payload: $this->readPayload(),
            );
        } catch (LocalManagedFileFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Managed file payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Managed file payload must be provided on stdin.');
        }

        /** @var mixed $payload */
        $payload = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Managed file payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
