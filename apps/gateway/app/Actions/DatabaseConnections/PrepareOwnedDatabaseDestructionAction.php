<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Support\ValidatedData;

/** One aggregate database intent owned by the requested lifecycle Instance.
 * @phpstan-import-type Server from DropOwnedDatabasesAction
 * @phpstan-import-type Sqlite from DropOwnedDatabasesAction
 */
final readonly class PrepareOwnedDatabaseDestructionAction
{
    public function __construct(private DropOwnedDatabasesAction $databases, private RecordDatabaseOperationAction $operations) {}

    /** @param list<int> $memberIds
     * @return array{owner_instance_id:int,intent_id:int,connection_ids:list<int>,servers:list<Server>,sqlite:list<Sqlite>}
     */
    public function execute(Instance $target, array $memberIds = []): array
    {
        $snapshot = $this->snapshot($target, $memberIds);
        // The run reads each server's admin login from the server's own vault key, so the intent holds no password.
        $prepared = $this->operations->prepare('instance.destroy', 'instance', $target->id, $target->node_id, $snapshot, []);

        return ['owner_instance_id' => $target->id, 'intent_id' => ValidatedData::integer($prepared->input['intent_id']), ...$snapshot];
    }

    /** Read-only ownership and removal admission, shared with Conn before any Schedule effect.
     * @param  list<int>  $memberIds
     * @return array{connection_ids:list<int>,servers:list<Server>,sqlite:list<Sqlite>}
     */
    public function snapshot(Instance $target, array $memberIds = []): array
    {
        $memberIds = $memberIds === [] ? [$target->id] : $memberIds;
        $owners = Instance::query()->whereKey($memberIds)->orderBy('id')->get();
        if (! in_array($target->id, $memberIds, true) || $owners->count() !== count(array_unique($memberIds))
            || $owners->contains(static fn (Instance $owner): bool => $owner->project_id !== $target->project_id || $owner->node_id !== $target->node_id || $owner->defaultAppEnv() !== 'development')) {
            throw new ResourceOperationException('database.removal_conflict', 'Database owners must belong to the admitted development removal set.', 409);
        }

        return $this->databases->snapshot(array_values($owners->all()));
    }
}
