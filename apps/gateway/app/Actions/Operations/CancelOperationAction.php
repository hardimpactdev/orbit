<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Records cancellation intent. The native workflow owns its terminal result. */
final readonly class CancelOperationAction
{
    public function __construct(private OperationStore $store) {}

    public function execute(Operation $operation, bool $targetEnding = false): Operation
    {
        DB::transaction(function () use ($operation): void {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (! $operation->status->terminal() && $operation->cancel_requested_at === null) {
                $this->store->change($operation, ['cancel_requested_at' => now()]);
            }
        });

        return $operation->refresh();
    }
}
