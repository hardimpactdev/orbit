<?php

declare(strict_types=1);

namespace App\Actions\WebSocket;

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use App\Models\WebSocketRegistryOperation;
use Illuminate\Support\Facades\DB;

/** R1: no Swamp run. Reverb pulls the registry and reports; the Gateway closes each task from those reports. */
final readonly class CloseWebSocketOperationsAction
{
    public const array Kinds = ['websocket.sync', 'websocket.app_create', 'websocket.app_destroy', 'websocket.app_rotate'];

    /** A serving process that is still stale or missing after this long fails the task. */
    public const int TimeoutSeconds = 300;

    public function __construct(private ReadWebSocketApplyStatusAction $status, private OperationStore $operations) {}

    public function execute(): void
    {
        $open = Operation::query()->whereIn('kind', self::Kinds)
            ->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])->orderBy('id')->pluck('id');
        foreach ($open as $id) {
            DB::transaction(function () use ($id): void {
                $operation = Operation::query()->whereKey($id)->lockForUpdate()->first();
                if ($operation === null || $operation->status->terminal()) {
                    return;
                }
                $registry = WebSocketRegistryOperation::query()->find($operation->input['registry_operation_id'] ?? null);
                $state = $registry === null ? 'pending' : $this->status->execute($registry)['state'];
                $expired = $operation->created_at->lessThanOrEqualTo(now()->subSeconds(self::TimeoutSeconds));
                $error = match (true) {
                    $state === 'applied' => null,
                    $state === 'superseded' => 'websocket.registry_superseded',
                    $expired => 'websocket.registry_pending',
                    default => false,
                };
                if ($error === false) {
                    return;
                }
                $this->operations->change($operation, $error === null
                    ? ['status' => OperationStatus::Succeeded, 'result' => ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']], 'finished_at' => now(), 'error_code' => null]
                    : ['status' => OperationStatus::Failed, 'result' => ['error_code' => $error], 'finished_at' => now(), 'error_code' => $error]);
            });
        }
    }
}
