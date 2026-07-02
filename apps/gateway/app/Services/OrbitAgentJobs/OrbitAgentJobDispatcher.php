<?php

declare(strict_types=1);

namespace App\Services\OrbitAgentJobs;

use App\Models\Node;
use App\Models\OrbitAgentJob;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class OrbitAgentJobDispatcher
{
    public function __construct(
        private OperationRunRecorder $operationRuns,
        private OrbitAgentJobPayloadPolicy $payloadPolicy,
        private OrbitAgentJobDefinitionRegistry $definitions,
        private OrbitAgentJobLifecycleRecorder $lifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queueNoop(Node $targetNode, array $payload = []): OrbitAgentJob
    {
        return $this->queue($targetNode, OrbitAgentJobDefinitionRegistry::TYPE_NOOP, $payload);
    }

    public function queueAppDevConvergence(Node $targetNode, string $tld): OrbitAgentJob
    {
        return $this->queue(
            targetNode: $targetNode,
            type: OrbitAgentJobDefinitionRegistry::TYPE_APP_DEV_CONVERGENCE,
            payload: $this->definitions->appDevConvergencePayload($tld),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(Node $targetNode, string $type, array $payload = []): OrbitAgentJob
    {
        if (! $this->isAgentCapable($targetNode)) {
            throw new InvalidArgumentException('Orbit Agent jobs require an active agent-capable node.');
        }

        $this->definitions->assertSupportedType($type);
        $this->payloadPolicy->assertSafe($payload, 'job');
        $this->definitions->assertPayloadMatchesType($type, $payload);

        /** @var OrbitAgentJob $job */
        $job = DB::transaction(function () use ($targetNode, $type, $payload): OrbitAgentJob {
            $operationRun = $this->operationRuns->queued(
                operationId: (string) Str::uuid(),
                lane: 'gateway',
                internalCommand: $this->definitions->internalCommandFor($type),
                operationType: 'orbit_agent_job',
                targetNodeId: $targetNode->id,
                queue: 'orbit-agent',
            );

            return OrbitAgentJob::query()->create([
                'type' => $type,
                'status' => OrbitAgentJobLifecycleRecorder::STATUS_QUEUED,
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
                ->where('status', OrbitAgentJobLifecycleRecorder::STATUS_QUEUED)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $job instanceof OrbitAgentJob) {
                return null;
            }

            $job->forceFill([
                'claimed_at' => now(),
            ])->save();

            return $this->lifecycle->record(
                job: $job->refresh(),
                node: $node,
                event: OrbitAgentJobLifecycleRecorder::STATUS_ACCEPTED,
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

        $this->payloadPolicy->assertSafe($payload, 'progress');

        return $this->lifecycle->record($job, $node, $event, $payload);
    }

    private function isAgentCapable(Node $node): bool
    {
        return $node->isActive() && $node->orbit_agent_capable;
    }
}
