<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationResultHook;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class VaultDirectResult implements DirectOperationResultHook
{
    public function input(Operation $operation): array
    {
        return ['action' => substr($operation->kind, 6)];
    }

    public function validate(Operation $operation, array $result, OperationStatus $status): array
    {
        $known = $operation->status->terminal() ? [] : app(VaultOperationSecrets::class)->resolve($operation, $operation->target_id, '', '')->strings();
        if (app(OperationExecutionContract::class)->result($operation, $result, $known) !== $result) {
            throw new ResourceOperationException('operation.result_invalid', 'Only safe Vault metadata is accepted.', 422);
        }

        return $result;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        app(VaultOperationCompletion::class)->complete($operation, $outcome);
    }
}
