<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceWake;
use App\Jobs\Operations\BroadcastOperationNotice;
use App\Models\Operation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Sends an operation's notices (docs/reference/events.mdx#operation): every change on the operation's own channel,
 * and its creation, status changes and cancel requests on `orbit`. A notice never carries input, steps, output, or the result.
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
        $this->broadcast($operation, RecordEventType::OperationCreated);
    }

    public function changed(Operation $operation, bool $statusChanged): void
    {
        $this->broadcast($operation, RecordEventType::OperationUpdated, self::channel($operation->id));

        if ($statusChanged) {
            $this->broadcast($operation, RecordEventType::OperationUpdated);
        }
    }

    /** Creation precedes the executor state stamp; an admitted executor wake also stays queued if the setting changes. */
    private function broadcast(Operation $operation, RecordEventType $type, string $channel = 'orbit'): void
    {
        $notice = self::notice($operation);
        if ($operation->kind === InstanceWake::Kind && (ExecutorWake::enabled() || ExecutorWake::owns($operation))) {
            $id = $operation->id;
            DB::afterCommit(static fn () => Bus::dispatch(new BroadcastOperationNotice($type, $id, $notice, $channel)));

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
