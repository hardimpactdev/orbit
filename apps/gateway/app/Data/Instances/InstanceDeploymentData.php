<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Models\InstanceDeployment;
use App\Models\Node;
use DateTimeInterface;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceDeploymentData extends Data
{
    public function __construct(
        public int $id,
        public int $instanceId,
        public string $kind,
        public ?string $release,
        public ?string $branch,
        public ?string $commit,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $durationSeconds,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $selectedRelease,
        public ?string $triggeredBy,
        public ?int $callerNodeId,
        public ?string $callerNodeName,
    ) {}

    public static function fromModel(InstanceDeployment $deployment): self
    {
        return new self(
            id: $deployment->id,
            instanceId: $deployment->instance_id,
            kind: $deployment->kind,
            release: $deployment->release,
            branch: $deployment->branch,
            commit: $deployment->commit,
            startedAt: $deployment->started_at->format(DateTimeInterface::ATOM),
            finishedAt: $deployment->finished_at?->format(DateTimeInterface::ATOM),
            durationSeconds: $deployment->duration_seconds,
            status: $deployment->status,
            failedStep: $deployment->failed_step,
            errorCode: $deployment->error_code,
            selectedRelease: $deployment->selected_release,
            triggeredBy: $deployment->triggered_by,
            callerNodeId: $deployment->caller_node_id,
            callerNodeName: $deployment->caller_node_id === null ? null : Node::query()->find($deployment->caller_node_id)?->name,
        );
    }
}
