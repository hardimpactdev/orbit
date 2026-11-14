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

/**
 * Failure notification at the committed state boundary, including failures before source resolves. A failed refresh that a release
 * admitted also leaves an Activity problem.
 */
final readonly class InstanceOperationAlerts
{
    public function __construct(private ReleaseAlertWebhook $webhook, private LogRedactor $redactor) {}

    public function changed(Operation $operation): void
    {
        if (! in_array($operation->kind, ['instance.create', 'instance.complete', 'instance.deploy'], true)
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
        if ($operation->kind === 'instance.deploy' && $operation->triggered_by === NestedFleetRefresh::Trigger) {
            $failure = ValidatedData::object($operation->state['native_failure'] ?? []);
            $detail = is_string($failure['detail'] ?? null) ? mb_strcut($this->redactor->redact($failure['detail'], []), 0, 1024, 'UTF-8') : null;
            $message = sprintf('The release refresh of Instance %d failed at %s (%s)%s', $operation->target_id, $payload['failed_step'] ?? 'unknown',
                $payload['error_code'] ?? 'unknown', $detail === null ? '.' : ': '.$detail);
            DB::afterCommit(fn () => $this->refreshFailed($payload, $message));
        }
    }

    /**
     * The refresh that a release admitted failed (docs/cli/instance/container/fleet-refresh.mmd): an Activity problem of its own, which
     * `activity:list` shows and the problem collector reads as a failure. The release has ended by then; nothing else would say so.
     *
     * @param  array<string,mixed>  $payload
     */
    private function refreshFailed(array $payload, string $message): void
    {
        try {
            Activity::query()->create(['log_name' => 'instances', 'description' => 'instance.refresh_failed',
                'subject_type' => Instance::MorphAlias, 'subject_id' => $payload['instance_id'],
                'command' => 'instances:refresh-nested-fleets', 'request_id' => (string) Str::uuid(),
                'status' => 'failed', 'error_code' => 'instance.refresh_failed',
                'properties' => ['operation_id' => $payload['operation_id'], 'instance_id' => $payload['instance_id'], 'node_id' => $payload['node_id'],
                    'failed_step' => $payload['failed_step'], 'operation_error_code' => $payload['error_code'],
                    'error_message' => $message]]);
        } catch (Throwable $exception) {
            report($exception);
        }
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
