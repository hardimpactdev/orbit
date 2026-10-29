<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Removes protected payloads after the retry window; public idempotency receipts remain. */
final readonly class PurgeDatabaseExecutionIntentsAction
{
    public function execute(): int
    {
        $cutoff = now()->subHours(DatabaseExecutionIntent::RETRY_HOURS);
        $retained = [];
        $children = [];
        foreach (DatabaseExecutionIntent::query()->cursor() as $intent) {
            $children[$intent->id] = array_map(static fn (array $child): int => ValidatedData::integer($child['input']['intent_id']), $intent->payload['projection']['children'] ?? []);
            if (isset($intent->payload['projection']['abandon_intent_id'])) {
                $children[$intent->id][] = $intent->payload['projection']['abandon_intent_id'];
            }
            if (isset($intent->payload['projection']['seed_intent_id'])) {
                $children[$intent->id][] = $intent->payload['projection']['seed_intent_id'];
            }
            if ($intent->created_at?->gte($cutoff)) {
                $retained[$intent->id] = true;
            }
            if ($intent->parent_operation_id !== null) {
                $parent = Operation::query()->find($intent->parent_operation_id);
                if ($parent === null || ! $parent->status->terminal() || $parent->finished_at === null || $parent->finished_at->gte($cutoff)) {
                    $retained[$intent->id] = true;
                }
            }
            foreach (Operation::query()->where('input->intent_id', $intent->id)->cursor() as $operation) {
                while ($operation !== null) {
                    if (! $operation->status->terminal() || $operation->finished_at === null || $operation->finished_at->gte($cutoff)) {
                        $retained[$intent->id] = true;
                        break;
                    }
                    $operation = $operation->parent_operation_id === null ? null : Operation::query()->find(ValidatedData::integer($operation->parent_operation_id));
                }
            }
        }
        $pending = array_keys($retained);
        while ($pending !== []) {
            $id = array_pop($pending);
            foreach ($children[$id] ?? [] as $child) {
                if (! isset($retained[$child])) {
                    $retained[$child] = true;
                    $pending[] = $child;
                }
            }
        }

        return ValidatedData::integer(DatabaseExecutionIntent::query()->where('created_at', '<', $cutoff)->whereNotIn('id', array_keys($retained))->delete());
    }
}
