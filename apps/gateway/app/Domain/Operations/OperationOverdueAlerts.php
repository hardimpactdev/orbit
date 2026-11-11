<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Releases\ReleaseAlertWebhook;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Called with the Operation locked: the mark and Activity commit together, and the webhook follows commit. */
final readonly class OperationOverdueAlerts
{
    public function __construct(private ReleaseAlertWebhook $webhook) {}

    public function raise(Operation $operation): void
    {
        $soft = OperationRunBudgets::softDeadline($operation);
        if ($soft === null || $soft->isFuture() || isset($operation->state['overdue_since'])) {
            return;
        }
        $since = $soft->toISOString();
        app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'overdue_since' => $since]]);
        $payload = ['event' => 'operation.overdue', 'operation_id' => $operation->id, 'kind' => $operation->kind,
            'target_type' => $operation->target_type, 'target_id' => $operation->target_id, 'node_id' => $operation->node_id,
            'overdue_since' => $since, 'soft_limit_seconds' => OperationRunBudgets::seconds($operation),
            'occurred_at' => now()->toISOString(), 'request_id' => (string) Str::uuid(),
            'text' => sprintf('Operation %d (%s) is overdue and keeps running.', $operation->id, $operation->kind)];
        $activity = Activity::query()->create(['log_name' => 'operations', 'description' => 'operation.overdue',
            'command' => 'operation:overdue-alert', 'request_id' => $payload['request_id'], 'status' => 'running', 'properties' => $payload]);
        DB::afterCommit(function () use ($activity, $payload): void {
            $receipt = $this->webhook->send($payload);
            $activity->update(['status' => $receipt->failedStep() ? 'failed' : 'succeeded',
                'error_code' => $receipt->failedStep() ? 'operation.alert_webhook_failed' : null,
                'properties' => [...$payload, 'webhook' => $receipt->toArray()]]);
        });
    }
}
