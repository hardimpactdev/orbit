<?php

declare(strict_types=1);

namespace App\Services\OrbitAgentJobs;

use App\Models\Node;
use App\Models\OrbitAgentJob;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orbit\Core\Enums\OperationStatus;

final readonly class OrbitAgentJobLifecycleRecorder
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_PRIVILEGE_REQUESTED = 'privilege_requested';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    private const array ALLOWED_EVENTS = [
        self::STATUS_ACCEPTED,
        self::STATUS_RUNNING,
        self::STATUS_PRIVILEGE_REQUESTED,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public function __construct(
        private OperationRunRecorder $operationRuns,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(OrbitAgentJob $job, Node $node, string $event, array $payload = []): OrbitAgentJob
    {
        if (! in_array($event, self::ALLOWED_EVENTS, strict: true)) {
            throw new InvalidArgumentException('Unsupported Orbit Agent lifecycle event.');
        }

        /** @var OrbitAgentJob $recordedJob */
        $recordedJob = DB::transaction(function () use ($job, $node, $event, $payload): OrbitAgentJob {
            $operationRunId = $job->operation_run_id;

            if ($operationRunId === null) {
                throw new InvalidArgumentException('Orbit Agent job is not linked to an operation run.');
            }

            $operationStatus = $this->operationStatusForEvent($event);

            if ($operationStatus === OperationStatus::Running) {
                $this->operationRuns->running($operationRunId);
            }

            if ($operationStatus === OperationStatus::Succeeded) {
                $this->operationRuns->succeeded($operationRunId, result: $this->operationResult($job, $event));
            }

            if ($operationStatus === OperationStatus::Failed) {
                $this->operationRuns->failed($operationRunId, error: $this->operationResult($job, $event));
            }

            $this->operationRuns->appendEvent(
                operationRunId: $operationRunId,
                eventType: 'orbit_agent_job.lifecycle',
                payload: [
                    'job_id' => $job->id,
                    'job_type' => $job->type,
                    'event' => $event,
                    'payload_keys' => array_values(array_filter(
                        array_keys($payload),
                        is_string(...),
                    )),
                ],
                metadata: [
                    'target_node' => $node->name,
                ],
            );

            $job->forceFill([
                'status' => $event,
                'finished_at' => in_array($event, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], strict: true)
                    ? Carbon::now()
                    : $job->finished_at,
            ])->save();

            $this->logActivity($job->refresh(), $node, $event);

            return $job->refresh();
        });

        assert($recordedJob instanceof OrbitAgentJob, description: 'Lifecycle transaction returns the updated job.');

        return $recordedJob;
    }

    private function operationStatusForEvent(string $event): OperationStatus
    {
        return match ($event) {
            self::STATUS_RUNNING, self::STATUS_PRIVILEGE_REQUESTED => OperationStatus::Running,
            self::STATUS_SUCCEEDED => OperationStatus::Succeeded,
            self::STATUS_FAILED => OperationStatus::Failed,
            default => OperationStatus::Queued,
        };
    }

    /**
     * @return array{job_id: string, job_type: string, event: string}
     */
    private function operationResult(OrbitAgentJob $job, string $event): array
    {
        return [
            'job_id' => $job->id,
            'job_type' => $job->type,
            'event' => $event,
        ];
    }

    private function logActivity(OrbitAgentJob $job, Node $node, string $event): void
    {
        activity('orbit_agent')
            ->event("orbit_agent_job.{$event}")
            ->performedOn($job)
            ->causedBy($node)
            ->withProperties([
                'type' => 'write',
                'job_id' => $job->id,
                'job_type' => $job->type,
                'job_status' => $job->status,
                'operation_run_id' => $job->operation_run_id,
                'event' => $event,
                'target_node' => $node->name,
            ])
            ->log("orbit agent job {$event}");
    }
}
