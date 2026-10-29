<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Derives compensation only from a transfer's protected, recorded seed attempt. */
final readonly class PrepareSqliteSeedAbandonAction
{
    public function __construct(private RecordDatabaseOperationAction $records) {}

    /** @return array{key:string,kind:string,node_id:int,input:array<string,mixed>} */
    public function execute(Instance $target, int $seedIntentId, Operation $parent): array
    {
        return DB::transaction(function () use ($target, $seedIntentId, $parent): array {
            $seed = DatabaseExecutionIntent::query()->lockForUpdate()->findOrFail($seedIntentId);
            $attempt = $seed->payload['projection']['seed_attempt'] ?? null;
            $command = $seed->payload['commands']['database-apply'] ?? null;
            if ($seed->target_type !== 'instance' || $seed->target_id !== $target->id
                || ($seed->payload['admission']['kind'] ?? null) !== 'database.sqlite_seed'
                || $parent->target_type !== 'instance' || $parent->target_id !== $target->id
                || ! is_array($attempt) || trim($attempt['id']) === '' || $attempt['operation'] === ''
                || ($command['name'] ?? null) !== 'instance.install-sqlite-seed'
                || ($command['input']['environment'] ?? null) !== 'development'
                || ($command['input']['operation'] ?? null) !== $attempt['operation']) {
                throw new ResourceOperationException('sqlite.seed_abandon_forbidden', 'The recorded intent is not this Instance transfer seed.', 409);
            }
            $this->assertParent($seed, $parent);
            if (isset($seed->payload['projection']['abandon_intent_id'])) {
                $abandon = DatabaseExecutionIntent::recorded($seed->payload['projection']['abandon_intent_id']);
                $admission = $abandon->payload['admission'] ?? null;
                if ($abandon->target_type !== 'instance' || $abandon->target_id !== $target->id || $abandon->node_id !== $seed->node_id
                    || ($admission['kind'] ?? null) !== 'database.sqlite_seed_abandon'
                    || ($abandon->payload['projection']['seed_intent_id'] ?? null) !== $seed->id) {
                    throw new ResourceOperationException('sqlite.seed_abandon_forbidden', 'The prepared cleanup does not match the seed.', 409);
                }

                return (new PreparedDatabaseOperation($admission['kind'], $abandon->target_type, $abandon->target_id,
                    $abandon->node_id, $admission['input']))->child($parent);
            }
            $source = DatabaseExecutionIntent::recorded($attempt['source_cleanup_intent_id']);
            $sourceCommand = $source->payload['commands']['database-apply'];
            if ($source->target_type !== 'instance' || $source->target_id !== $target->id || $source->node_id === $seed->node_id
                || $source->parent_operation_id !== $seed->parent_operation_id
                || ($source->payload['admission']['kind'] ?? null) !== 'database.sqlite_cleanup'
                || $sourceCommand['name'] !== 'instance.prepare-sqlite-seed'
                || ($sourceCommand['input']['mode'] ?? null) !== 'cleanup'
                || ($sourceCommand['input']['operation'] ?? null) !== $attempt['operation']) {
                throw new ResourceOperationException('sqlite.seed_abandon_forbidden', 'The seed source cleanup does not match its recorded attempt.', 409);
            }
            $sourceCleanup = $this->records->prepare('database.sqlite_cleanup', 'instance', $target->id, $source->node_id,
                ['owner_instance_id' => $target->id, 'driver' => 'seed'],
                ['database-apply' => ['name' => $sourceCommand['name'], 'input' => $sourceCommand['input'], 'backend' => 'absent']]);
            $cleanupInput = [...$command['input'], 'mode' => 'cleanup', 'expected_size' => 0, 'expected_digest' => ''];
            $prepared = $this->records->prepare('database.sqlite_seed_abandon', 'instance', $target->id, $seed->node_id,
                ['owner_instance_id' => $target->id, 'driver' => 'seed'],
                ['database-apply' => ['name' => $command['name'], 'input' => $cleanupInput, 'backend' => 'absent', 'expect_state' => 'cleaned']],
                ['seed_intent_id' => $seed->id, 'children' => [[...$sourceCleanup->child(), 'phase' => 'after']]]);
            $child = $prepared->child($parent);
            $seed->update(['payload' => [...$seed->payload, 'projection' => [...$seed->payload['projection'], 'abandon_intent_id' => $prepared->input['intent_id']]]]);

            return $child;
        });
    }

    private function assertParent(DatabaseExecutionIntent $seed, Operation $parent): void
    {
        $ancestor = $parent;
        while ($ancestor->id !== $seed->parent_operation_id) {
            if ($ancestor->parent_operation_id === null) {
                throw new ResourceOperationException('sqlite.seed_abandon_forbidden', 'The seed belongs to another transfer Operation.', 409);
            }
            $ancestor = Operation::query()->findOrFail(ValidatedData::integer($ancestor->parent_operation_id));
        }
    }
}
