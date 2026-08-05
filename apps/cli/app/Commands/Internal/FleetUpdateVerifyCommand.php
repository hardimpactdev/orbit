<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Operations\LocalFleetUpdateVerifyAction;
use App\Services\Operations\LocalFleetUpdateVerifyFailure;
use InvalidArgumentException;
use JsonException;

final class FleetUpdateVerifyCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:fleet-update:verify {check} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Verify local fleet update readiness through typed internal checks';

    public function handle(LocalFleetUpdateVerifyAction $verifier): int
    {
        if (! $this->verifyOperationToken('internal:fleet-update:verify')) {
            return self::FAILURE;
        }

        try {
            $result = $verifier->run(
                check: $this->argument('check'),
                payload: $this->payload(),
            );
        } catch (LocalFleetUpdateVerifyFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Fleet update verification payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $stdin = trim($this->stdin());

        if ($stdin === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Fleet update verification payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Fleet update verification payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
