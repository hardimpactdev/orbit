<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;

final readonly class OfflineResidueCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        foreach ($operation->state['offline_residue_plan']['rows'] as $snapshot) {
            $row = RouteRemovalResidue::query()->lockForUpdate()->where('node_id', $operation->node_id)->find($snapshot['id']);
            if ($row !== null && $row->route_id === $snapshot['route_id'] && $row->domain === $snapshot['domain'] && $row->steps === $snapshot['steps'] && $row->attempts === $snapshot['attempts']) {
                $row->delete();
            }
        }
    }
}
