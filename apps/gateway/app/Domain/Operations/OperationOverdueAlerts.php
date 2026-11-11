<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Releases\ReleaseAlertWebhook;
use App\Jobs\Operations\SendOperationOverdueAlert;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Support\Str;
use Throwable;

/** Schedules the existing timeout alert once; a separate worker delivers it. */
final readonly class OperationOverdueAlerts
{
    public function __construct(private ReleaseAlertWebhook $webhook) {}

    public function raise(Operation $operation, int $budget, string $since): void
    {
        $payload = ['event' => 'operation.overdue', 'operation_id' => $operation->id, 'kind' => $operation->kind,
            'target_type' => $operation->target_type, 'target_id' => $operation->target_id, 'node_id' => $operation->node_id,
            'overdue_since' => $since, 'run_budget_seconds' => $budget,
            'occurred_at' => now()->toISOString(), 'request_id' => (string) Str::uuid(),
            'text' => sprintf('Operation %d (%s) exceeded its run budget.', $operation->id, $operation->kind)];
        SendOperationOverdueAlert::dispatch($payload)->afterCommit();
    }

    /** @param array<string,mixed> $payload */
    public function send(array $payload): void
    {
        $activity = null;
        try {
            $activity = Activity::query()->create(['log_name' => 'operations', 'description' => 'operation.overdue',
                'command' => 'operation:overdue-alert', 'request_id' => $payload['request_id'], 'status' => 'running', 'properties' => $payload]);
        } catch (Throwable $exception) {
            report($exception);
        }
        try {
            $receipt = $this->webhook->send($payload);
            $activity?->update(['status' => $receipt->failedStep() ? 'failed' : 'succeeded',
                'error_code' => $receipt->failedStep() ? 'operation.alert_webhook_failed' : null,
                'properties' => [...$payload, 'webhook' => $receipt->toArray()]]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
