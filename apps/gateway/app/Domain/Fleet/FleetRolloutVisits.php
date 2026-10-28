<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FleetRolloutNode;
use App\Models\Operation;

/** One fleet visit at a time, including visits from a superseded rollout generation. */
final readonly class FleetRolloutVisits
{
    public const string AdmissionLock = 'fleet-rollout-admission';

    public function busy(): bool
    {
        return FleetRolloutNode::query()->whereNotNull('started_at')->whereNull('finished_at')->exists()
            || Operation::query()->where('kind', 'fleet.rollout')->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])->exists();
    }

    public function assertIdle(): void
    {
        if ($this->busy()) {
            throw new ResourceOperationException('fleet.rollout_busy', 'A fleet Node visit is still open.', 409);
        }
    }
}
