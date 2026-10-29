<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use Illuminate\Support\Facades\DB;

/** Retained Gateway caller. Admission records intent; only Swamp performs removal. */
final readonly class DestroyInstanceAction implements InstanceRemover
{
    public function __construct(private StartInstanceDestroyAction $start, private AwaitInstanceRemovalAction $await) {}

    public function execute(Instance $instance, bool $force): InstanceRemoval
    {
        if (DB::transactionLevel() !== 0) {
            throw new ResourceOperationException('operation.await_in_transaction', 'Commit the caller transaction before admitting and awaiting Instance removal.', 409);
        }
        $operation = $this->start->execute($instance, $force, 'gateway', null);

        return $this->await->execute($operation, 120);
    }
}
