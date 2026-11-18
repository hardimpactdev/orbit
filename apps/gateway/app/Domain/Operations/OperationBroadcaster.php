<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\ExecutorWakeNotices;
use App\Domain\Instances\Power\InstanceWake;
use App\Models\Operation;

/**
 * Sends an operation's notices (docs/reference/events.mdx#operation): every change on the operation's own channel,
 * and its creation, status changes and cancel requests on `orbit`. A notice never carries input, steps, output, or the result.
 */
final readonly class OperationBroadcaster
{
    public function __construct(private RecordEventBroadcaster $broadcaster, private ExecutorWakeNotices $wakeNotices) {}

    public static function channel(int $operationId): string
    {
        return "operation.{$operationId}";
    }

    public function created(Operation $operation): void
    {
        $this->broadcast($operation, RecordEventType::OperationCreated);
    }

    public function changed(Operation $operation, bool $statusChanged): void
    {
        $this->broadcast($operation, RecordEventType::OperationUpdated, self::channel($operation->id));

        if ($statusChanged) {
            $this->broadcast($operation, RecordEventType::OperationUpdated);
        }
    }

    public static function usesWakeOutbox(Operation $operation): bool
    {
        return $operation->kind === InstanceWake::Kind && (ExecutorWake::enabled() || ExecutorWake::owns($operation));
    }

    private function broadcast(Operation $operation, RecordEventType $type, string $channel = 'orbit'): void
    {
        if (in_array($operation->kind, OperationKinds::PrivateConnKinds, true)) {
            return;
        }
        $notice = self::notice($operation);
        if (self::usesWakeOutbox($operation)) {
            $this->wakeNotices->record($operation, $type, $notice, $channel);

            return;
        }
        $this->broadcaster->broadcast($type, $operation->id, $notice, $channel);
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
