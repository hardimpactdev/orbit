<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\Operations\OperationStatus;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Removes protected material after its Operation and retry window finish. */
final readonly class PurgeDatabaseExecutionIntentsAction
{
    public function execute(): int
    {
        $cutoff = now()->subHours(DatabaseExecutionIntent::RETRY_HOURS);
        $retained = [];
        $operations = Operation::query()->where(function ($query) use ($cutoff): void {
            $query->whereNotIn('status', OperationStatus::terminalValues())
                ->orWhereNull('finished_at')->orWhere('finished_at', '>=', $cutoff);
        });
        foreach ($operations->cursor() as $operation) {
            $id = app(DatabaseIntentSource::class)->ownerId($operation);
            if (is_int($id)) {
                $retained[] = $id;
            }
        }

        return ValidatedData::integer(DatabaseExecutionIntent::query()->where('created_at', '<', $cutoff)->whereNotIn('id', $retained)->delete());
    }
}
