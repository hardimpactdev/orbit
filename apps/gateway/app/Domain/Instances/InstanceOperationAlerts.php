<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Failure notification at the committed state boundary, including failures before source resolves. */
final readonly class InstanceOperationAlerts
{
    public function __construct(private ReleaseAlertWebhook $webhook, private LogRedactor $redactor) {}

    public function changed(Operation $operation): void
    {
        if (! in_array($operation->kind, ['instance.create', 'instance.deploy'], true)
            || ! $operation->wasChanged('status')
            || ! in_array($operation->status, [OperationStatus::Failed, OperationStatus::Interrupted], true)
            || OperationStatus::from(ValidatedData::string($operation->getRawOriginal('status')))->terminal()) {
            return;
        }
        $payload = ['event' => 'instance.operation_failed', 'operation_id' => $operation->id,
            'kind' => $operation->kind, 'instance_id' => $operation->target_id, 'node_id' => $operation->node_id,
            'failed_step' => $this->text($operation->failed_step ?? $operation->result['failed_step'] ?? null),
            'error_code' => $this->text($operation->error_code),
            'root_run_id' => $this->text($operation->state['swamp_run_id'] ?? null),
            'occurred_at' => now()->toISOString(), 'request_id' => (string) Str::uuid()];
        $payload['text'] = sprintf('%s Operation %d failed for Instance %d on Node %s at %s (%s).',
            $operation->kind, $operation->id, $operation->target_id, $operation->node_id,
            $payload['failed_step'] ?? 'unknown', $payload['error_code'] ?? 'unknown');
        DB::afterCommit(fn () => $this->send($payload));
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload): void
    {
        $activity = null;
        try {
            $activity = Activity::query()->create(['log_name' => 'instances', 'description' => 'instance.operation_failed',
                'subject_type' => Instance::MorphAlias, 'subject_id' => $payload['instance_id'],
                'command' => 'instance:failure-alert', 'request_id' => $payload['request_id'],
                'status' => 'running', 'properties' => $payload]);
        } catch (Throwable $exception) {
            report($exception);
        }
        try {
            $receipt = $this->webhook->send($payload);
            $activity?->update(['status' => $receipt->failedStep() ? 'failed' : 'succeeded',
                'error_code' => $receipt->failedStep() ? 'instance.alert_webhook_failed' : null,
                'properties' => [...$payload, 'webhook' => $receipt->toArray()]]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? mb_strcut($this->redactor->redact($value, []), 0, 255, 'UTF-8') : null;
    }
}
