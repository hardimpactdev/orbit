<?php

declare(strict_types=1);

namespace App\Services\OrbitAgentJobs;

use App\Models\Node;
use App\Models\OrbitAgentJob;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Orbit\Core\Enums\OperationStatus;

final readonly class OrbitAgentJobDispatcher
{
    private const string TYPE_NOOP = 'noop';

    private const string STATUS_QUEUED = 'queued';

    private const string STATUS_ACCEPTED = 'accepted';

    private const string STATUS_RUNNING = 'running';

    private const string STATUS_PRIVILEGE_REQUESTED = 'privilege_requested';

    private const string STATUS_SUCCEEDED = 'succeeded';

    private const string STATUS_FAILED = 'failed';

    private const array ALLOWED_EVENTS = [
        self::STATUS_ACCEPTED,
        self::STATUS_RUNNING,
        self::STATUS_PRIVILEGE_REQUESTED,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public function __construct(
        private OperationRunRecorder $operationRuns,
        private OrbitAgentJobPayloadPolicy $payloadPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queueNoop(Node $targetNode, array $payload = []): OrbitAgentJob
    {
        return $this->queue($targetNode, self::TYPE_NOOP, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(Node $targetNode, string $type, array $payload = []): OrbitAgentJob
    {
        if (! $this->isAgentCapable($targetNode)) {
            throw new InvalidArgumentException('Orbit Agent jobs require an active agent-capable node.');
        }

        if ($type !== self::TYPE_NOOP) {
            throw new InvalidArgumentException('Orbit Agent jobs only support typed noop work.');
        }

        $this->payloadPolicy->assertSafe($payload, 'job');

        /** @var OrbitAgentJob $job */
        $job = DB::transaction(function () use ($targetNode, $type, $payload): OrbitAgentJob {
            $operationRun = $this->operationRuns->queued(
                operationId: (string) Str::uuid(),
                lane: 'gateway',
                internalCommand: 'orbit-agent:noop',
                operationType: 'orbit_agent_job',
                targetNodeId: $targetNode->id,
                queue: 'orbit-agent',
            );

            return OrbitAgentJob::query()->create([
                'type' => $type,
                'status' => self::STATUS_QUEUED,
                'target_node_id' => $targetNode->id,
                'operation_run_id' => $operationRun->id,
                'payload' => $payload,
            ]);
        });

        assert($job instanceof OrbitAgentJob, description: 'Queued Orbit Agent job transaction returns a job.');

        return $job;
    }

    public function claimNext(Node $node): ?OrbitAgentJob
    {
        if (! $this->isAgentCapable($node)) {
            return null;
        }

        /** @var OrbitAgentJob|null $claimedJob */
        $claimedJob = DB::transaction(function () use ($node): ?OrbitAgentJob {
            /** @var OrbitAgentJob|null $job */
            $job = OrbitAgentJob::query()
                ->where('target_node_id', $node->id)
                ->where('status', self::STATUS_QUEUED)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $job instanceof OrbitAgentJob) {
                return null;
            }

            $job->forceFill([
                'status' => self::STATUS_ACCEPTED,
                'claimed_at' => Carbon::now(),
            ])->save();

            return $this->recordEvent(
                job: $job->refresh(),
                node: $node,
                event: self::STATUS_ACCEPTED,
                payload: [],
            );
        });

        if (! $claimedJob instanceof OrbitAgentJob) {
            return null;
        }

        return $claimedJob;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(OrbitAgentJob $job, Node $node, string $event, array $payload = []): OrbitAgentJob
    {
        if (! $this->isAgentCapable($node)) {
            throw new InvalidArgumentException('Orbit Agent lifecycle reports require an active agent-capable node.');
        }

        if ($job->target_node_id !== $node->id) {
            throw new InvalidArgumentException('Orbit Agent job does not belong to the caller node.');
        }

        if (! in_array($event, self::ALLOWED_EVENTS, strict: true)) {
            throw new InvalidArgumentException('Unsupported Orbit Agent lifecycle event.');
        }

        $this->payloadPolicy->assertSafe($payload, 'progress');

        /** @var OrbitAgentJob $recordedJob */
        $recordedJob = DB::transaction(function () use ($job, $node, $event, $payload): OrbitAgentJob {
            $status = $this->statusForEvent($event);
            $operationRunId = $job->operation_run_id;

            if ($operationRunId === null) {
                throw new InvalidArgumentException('Orbit Agent job is not linked to an operation run.');
            }

            $operationStatus = $this->operationStatusForEvent($event);

            if ($operationStatus === OperationStatus::Running) {
                $this->operationRuns->running($operationRunId);
            }

            if ($operationStatus === OperationStatus::Succeeded) {
                $this->operationRuns->succeeded($operationRunId, result: [
                    'job_id' => $job->id,
                    'job_type' => $job->type,
                    'event' => $event,
                ]);
            }

            if ($operationStatus === OperationStatus::Failed) {
                $this->operationRuns->failed($operationRunId, error: [
                    'job_id' => $job->id,
                    'job_type' => $job->type,
                    'event' => $event,
                ]);
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
                'status' => $status,
                'finished_at' => in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], strict: true)
                    ? Carbon::now()
                    : $job->finished_at,
            ])->save();

            $this->logActivity($job->refresh(), $node, $event);

            return $job->refresh();
        });

        assert($recordedJob instanceof OrbitAgentJob, description: 'Lifecycle transaction returns the updated job.');

        return $recordedJob;
    }

    private function statusForEvent(string $event): string
    {
        return match ($event) {
            self::STATUS_ACCEPTED => self::STATUS_ACCEPTED,
            self::STATUS_RUNNING => self::STATUS_RUNNING,
            self::STATUS_PRIVILEGE_REQUESTED => self::STATUS_PRIVILEGE_REQUESTED,
            self::STATUS_SUCCEEDED => self::STATUS_SUCCEEDED,
            self::STATUS_FAILED => self::STATUS_FAILED,
            default => throw new InvalidArgumentException('Unsupported Orbit Agent lifecycle event.'),
        };
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

    private function isAgentCapable(Node $node): bool
    {
        return $node->isActive() && $node->orbit_agent_capable;
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
