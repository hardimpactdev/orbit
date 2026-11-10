<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The run input and the end of a move (docs/cli/instance/migrate.mmd). A move whose Route serves the container makes the container
 * the Instance's runtime: its checkout path becomes the workspace, the runtime runs, and a non-default Instance's T3 server is
 * recorded. A move that failed and restored drops the reserved runtime row, so the Instance is the checkout Instance it was.
 */
final readonly class InstanceMigrateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $instance = Instance::query()->findOrFail($operation->target_id);
        $container = ValidatedData::object($input['container']);
        $publication = ($input['route_publication'] ?? null) === null ? null : ValidatedData::object($input['route_publication']);
        // Swamp evaluates the agent step's inputs for a default too, so every run has the copy; only an Instance with T3 holds a key.
        $model = ($container['clone'] ?? null) === null ? "# The default Instance runs no agent.\n"
            : app(InstanceContainer::class)->modelEnvironment($instance->runtime()->firstOrFail(), app(TaskVmModelRoute::class)->key());

        return ['intent' => [
            'instance_id' => $instance->id, 'instance_name' => $instance->name, 'ssh_execution' => $input['ssh_execution'],
            'container' => $container, 'migrate' => ValidatedData::object($input['migrate']),
            'route_mutations' => $publication === null ? null : ValidatedData::object($publication['route_mutations']),
        ], 'execution_secrets' => [
            ...($publication === null ? [] : ValidatedData::object($publication['execution_secrets'])),
            'model_environment' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-migrate-model-'.$operation->id, $model),
        ]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $container = ValidatedData::object($operation->input['container'] ?? []);
        $result = ValidatedData::object($data['result'] ?? []);
        // From the removal of the checkout on, the container is the Instance, whatever the removal itself did.
        $moved = ($result['moved'] ?? false) === true;
        // The T3 session is read from the vault before the transaction, so no lock waits on Swamp.
        $server = $moved && ($container['clone'] ?? null) !== null
            ? app(DevelopmentInstanceCreateProjection::class)->server(ValidatedData::object(ValidatedData::object($container['clone'])['t3']), $result) : null;

        return DB::transaction(function () use ($operation, $data, $result, $moved, $server): Operation {
            $operation->refresh();
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
            if (($data['status'] === 'succeeded' && (! $moved || ($result['ready'] ?? false) !== true))
                || ($moved && (($result['instance_id'] ?? null) !== $instance->id || ! is_string($result['commit'] ?? null)))) {
                throw new ResourceOperationException('instance.result_invalid', 'The move did not prove its complete terminal result.');
            }
            if (! $operation->status->terminal()) {
                $runtime = $instance->runtime()->first();
                if ($moved && $runtime !== null) {
                    $this->moved($instance, ValidatedData::object($operation->input['migrate']), ValidatedData::integerList($operation->input['sqlite_connection_ids'] ?? []));
                    // A default's clones start at the seed snapshot the run took of its commit.
                    $runtime->update(['power_state' => InstanceRuntimePowerState::Running,
                        ...($server === null ? ['seed_commit' => ValidatedData::string($result['commit'])] : [])]);
                    if ($server !== null) {
                        app(InstanceLifecycleContributions::class)->projectCreate($instance, $server);
                    }
                } elseif (in_array($data['status'], ['failed', 'cancelled', 'interrupted'], true) && ($result['restored'] ?? false) === true
                    && ! (($result['preflight_refused'] ?? false) === true && ($operation->input['had_runtime_reservation'] ?? false) === true)) {
                    // The run removed its container, so the address is free again. A restore that failed keeps the row and its address.
                    $runtime?->delete();
                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /**
     * The Instance's records after the move. The workspace is its checkout from now on; `.env` came as it was, with the checkout's
     * path rewritten, so the stored values follow. A SQLite file moved with the files and is part of the workspace now, as a
     * container Instance's is: its connection record goes.
     *
     * @param  array<string, mixed>  $migrate
     * @param  list<int>  $sqlite
     */
    private function moved(Instance $instance, array $migrate, array $sqlite): void
    {
        $checkout = rtrim(ValidatedData::string($migrate['checkout']), '/');
        $store = app(InstanceEnvironmentStore::class);
        $rewritten = [];
        foreach ($store->values($instance) as $key => $value) {
            if (str_contains($value, $checkout)) {
                $rewritten[$key] = str_replace($checkout, InstanceHostIntent::Workspace, $value);
            }
        }
        if ($rewritten !== []) {
            $store->seedForCreate($instance, $rewritten);
        }
        DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->whereIn('database_connection_id', $sqlite)->delete();
        DatabaseConnection::query()->whereIn('id', $sqlite)->where('owner_instance_id', $instance->id)
            ->whereNotIn('id', DatabaseConnectionTarget::query()->select('database_connection_id'))->delete();
        $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance), 'source_layout' => InstanceSourceLayout::Checkout,
            'registration_common_repository_path' => null, 'seed_repository' => null, 'seed_commit' => null, 'seed_path' => null,
            'failed_step' => null, 'error_code' => null]);
    }
}
