<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Records the database owner's seed intent and attaches only its proven installed database.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceSqliteSeedIntent
{
    private const array SeededCheckpoints = [
        'clone-sqlite-prepared', 'clone-sqlite-attached', 'clone-definitions-instantiated',
        'clone-runtime-prepared', 'clone-certificate-prepared', 'clone-firewall-prepared',
        'clone-workload-caddy-published', 'clone-router-certificate-prepared', 'clone-route-firewall-prepared',
        'clone-workload-verified', 'clone-router-caddy-published', 'clone-dns-published', 'active',
    ];

    public function __construct(private InstanceLifecycleState $state, private PrepareDatabaseChildAction $databases,
        private AttachDatabaseConnectionAction $attach, private RecordEventBroadcaster $broadcaster) {}

    public function retainedClone(Operation $operation): bool
    {
        return in_array($operation->input['initial_provisioning_step'] ?? null, self::SeededCheckpoints, true);
    }

    /** @return array{prepared:true,seed_required:bool} */
    public function prepareClone(Operation $operation): array
    {
        $target = $this->cloneTarget($operation);
        $retained = $this->retainedClone($operation);
        if (! $retained) {
            DB::transaction(function () use ($operation, $target): void {
                if (isset($operation->state['lifecycle_sqlite_seed'])) {
                    return;
                }
                $candidate = Instance::query()->findOrFail(ValidatedData::integer($operation->input['candidate_id']));
                if ($candidate->node_id !== $operation->input['candidate_node_id'] || $candidate->project_id !== $target->project_id) {
                    throw new ResourceOperationException('instance.clone_candidate_changed', 'The SQLite candidate placement changed.', 409);
                }
                $source = new SqliteSeedPlacement($candidate->id, ValidatedData::string($operation->input['candidate_environment']),
                    ValidatedData::string($operation->input['candidate_path']), ValidatedData::string($operation->input['candidate_user']),
                    Node::query()->findOrFail($candidate->node_id));
                $destination = new SqliteSeedPlacement($target->id, 'production', ValidatedData::string($operation->input['production_home']),
                    ValidatedData::string($operation->input['production_user']), $target->node);
                $child = $this->databases->sqliteSeed($source, $destination, ValidatedData::string($operation->input['sqlite_source_path']), $operation);
                app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []),
                    'lifecycle_sqlite_seed' => [...$child, 'key' => 'clone-sqlite']]]);
            });
        }

        return ['prepared' => true, 'seed_required' => ! $retained];
    }

    /** @return ChildIntent */
    public function cloneChild(Operation $operation): array
    {
        $target = $this->cloneTarget($operation);
        $child = $operation->state['lifecycle_sqlite_seed'] ?? null;
        if ($this->retainedClone($operation) || ! is_array($child) || ($child['key'] ?? null) !== 'clone-sqlite'
            || ($child['kind'] ?? null) !== 'database.sqlite_seed' || ($child['node_id'] ?? null) !== $target->node_id
            || ! is_array($child['input'] ?? null) || ($child['input']['owner_instance_id'] ?? null) !== $target->id
            || ($child['input']['source_instance_id'] ?? null) !== $operation->input['candidate_id']
            || ($child['input']['source_node_id'] ?? null) !== $operation->input['candidate_node_id']
            || ! is_int($child['input']['intent_id'] ?? null) || $child['input']['intent_id'] < 1) {
            throw new ResourceOperationException('operation.child_unproven', 'The database owner has not prepared this SQLite seed.', 409);
        }

        return ['key' => 'clone-sqlite', 'kind' => 'database.sqlite_seed', 'node_id' => $target->node_id,
            'input' => ValidatedData::object($child['input'])];
    }

    /** @return array{attached:true} */
    public function attachClone(Operation $operation): array
    {
        $target = $this->cloneTarget($operation);
        if (! $this->retainedClone($operation)) {
            $this->cloneChild($operation);
            app(InstanceLifecycleExecution::class)->assertChild($operation, 'clone-sqlite', 'database.sqlite_seed');
            if (! in_array($target->provisioning_step, self::SeededCheckpoints, true)) {
                $target->update(['provisioning_step' => 'clone-sqlite-prepared']);
            }
        }
        DB::transaction(function () use ($target): void {
            $slug = $target->project->slug.'-'.$target->name;
            if (strlen($slug) > 63) {
                $slug = rtrim(substr($slug, 0, 54), '-').'-'.substr(sha1($slug), 0, 8);
            }
            $path = rtrim(ValidatedData::string($target->production_home), '/').'/database.sqlite';
            $connection = DatabaseConnection::query()->firstOrCreate(['slug' => $slug], [
                'driver' => DatabaseDriver::Sqlite, 'owner_instance_id' => $target->id, 'node_id' => $target->node_id, 'path' => $path,
            ]);
            if ($connection->owner_instance_id !== $target->id || $connection->driver !== DatabaseDriver::Sqlite
                || $connection->node_id !== $target->node_id || $connection->path !== $path) {
                throw new ResourceOperationException('instance.clone_database_slug_taken', 'The cloned database connection has another owner or placement.', 409);
            }
            $this->attach->execute($target, $connection, 'DB', forClone: true, replace: true);
            if ($connection->wasRecentlyCreated) {
                $this->broadcaster->broadcast(RecordEventType::DatabaseCreated, $connection->id, DatabaseConnectionData::fromModel($connection)->toArray());
            }
            if (! in_array($target->provisioning_step, array_slice(self::SeededCheckpoints, 1), true)) {
                $target->update(['provisioning_step' => 'clone-sqlite-attached']);
            }
        });

        return ['attached' => true];
    }

    private function cloneTarget(Operation $operation): Instance
    {
        if ($operation->kind !== 'instance.clone' || ! is_string($operation->input['sqlite_source_path'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This clone has no SQLite seed intent.', 422);
        }

        return $this->state->instance($operation);
    }
}
