<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Actions\WebSocket\ReadWebSocketApplyStatusAction;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\WebSocketRegistryOperation;
use App\Support\ValidatedData;

/** Proves the current boot set, including every serving end of a role move. */
final readonly class WebSocketOperationCompletion implements OperationCompletion
{
    public function __construct(private ReadWebSocketApplyStatusAction $status) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $registry = WebSocketRegistryOperation::query()->whereKey(ValidatedData::string($operation->input['registry_operation_id']))->firstOrFail();
        if ($this->status->execute($registry)['state'] !== 'applied') {
            throw new ResourceOperationException('websocket.registry_pending', 'Every current Reverb process must confirm the registry revision.', 409);
        }
    }
}
