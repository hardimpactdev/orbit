<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\Operation;

/**
 * Sends an operation's notices (docs/reference/events.md#operation): every change on the operation's own channel,
 * and its creation and status changes on `orbit`. A notice never carries input, steps, output, or the result.
 */
final readonly class OperationBroadcaster
{
    public function __construct(private RecordEventBroadcaster $broadcaster) {}

    public static function channel(int $operationId): string
    {
        return "operation.{$operationId}";
    }

    public function created(Operation $operation): void
    {
        $this->broadcaster->broadcast(RecordEventType::OperationCreated, $operation->id, self::notice($operation));
    }

    public function changed(Operation $operation, bool $statusChanged): void
    {
        $notice = self::notice($operation);
        $this->broadcaster->broadcast(RecordEventType::OperationUpdated, $operation->id, $notice, self::channel($operation->id));

        if ($statusChanged) {
            $this->broadcaster->broadcast(RecordEventType::OperationUpdated, $operation->id, $notice);
        }
    }

    /** @return array{id: int, kind: string, target_type: string, target_id: int, status: string, sequence: int, current_step: string|null} */
    public static function notice(Operation $operation): array
    {
        return [
            'id' => $operation->id,
            'kind' => $operation->kind,
            'target_type' => $operation->target_type,
            'target_id' => $operation->target_id,
            'status' => $operation->status->value,
            'sequence' => $operation->sequence,
            'current_step' => $operation->current_step,
        ];
    }
}
