<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * A removal's run withdrew its Route (docs/reference/instance-removal.md). The removal records when, so a retry of a removal that
 * failed later skips the withdraw and takes no Route publication hold: the Route is retiring, its sites unpublished.
 */
final class InstanceDestroyRouteWithdrawal
{
    public function record(Operation $operation): void
    {
        if ($operation->kind !== 'instance.destroy' || $operation->status->terminal() || ! is_string($operation->input['removal_id'] ?? null)) {
            return;
        }
        $removal = InstanceRemoval::query()->lockForUpdate()->find(ValidatedData::string($operation->input['removal_id']));
        if ($removal !== null && $removal->route_withdrawn_at === null) {
            $removal->update(['route_withdrawn_at' => now()]);
        }
    }
}
