<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\Deployment\InstanceRuntimeDefinitions;
use App\Domain\Instances\Deployment\RecordedReleases;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Starts the `instance.rollback` operation (docs/cli/instance/rollback.mmd). It refuses an Instance that cannot be
 * rolled back, or a release its deployment history does not retain, before it records anything. Admission holds the
 * Instance, freezes the full intent for the native run, and records the running rollback, which pauses automatic
 * deployment at once. Swamp then selects the release; the Gateway records the outcome from the run.
 */
final readonly class StartInstanceRollbackAction
{
    public function __construct(
        private InstanceDeploymentIntent $intent,
        private InstanceRuntimeDefinitions $definitions,
        private InstanceDeploymentRecorder $history,
        private StartOperationAction $operations,
    ) {}

    public function execute(Instance $instance, string $release, string $triggeredBy, ?string $requestId): Operation
    {
        return DB::transaction(function () use ($instance, $release, $triggeredBy, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            if (! $instance->placedOnAppProd()) {
                throw new ResourceOperationException('deployment_config.unavailable', 'Only a production Instance can be rolled back.', 409);
            }
            $deployment = $this->intent->snapshot($instance, rollback: true);
            $retained = RecordedReleases::of($instance);
            if (! array_key_exists($release, $retained)) {
                throw new ResourceOperationException('rollback.release_not_retained', "The Instance does not retain the release [{$release}]. List its releases with instance:release:list.", 422, details: ['release' => $release]);
            }
            $operation = $this->operations->execute(OperationKinds::InstanceRollback, OperationKinds::InstanceTarget, $instance->id, $instance->node_id, [
                'instance_id' => $instance->id, 'release' => $release, 'commit' => $retained[$release], 'deployment' => $deployment,
                'php_input' => $deployment['php'] === true ? ['service' => ProductionPhpRuntimeIdentity::from($instance)->service] : null,
                'runtime_definitions' => ['restart' => $this->restart($instance)],
            ], $triggeredBy, $requestId, lockKey: OperationStore::lockKey(OperationKinds::InstanceTarget, $instance->id));
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            // The running row pauses automatic deployment now, not when the run ends.
            $row = $this->history->start($instance, $triggeredBy, kind: InstanceDeployment::Rollback);
            $operation->forceFill(['state' => [...($operation->state ?? []), 'deployment_id' => $row->id]])->save();

            return $operation;
        });
    }

    /** @return list<array{process_id:int,runtime:string,name:string}> */
    private function restart(Instance $instance): array
    {
        return array_map(static fn (array $process): array => ['process_id' => ValidatedData::integer($process['process_id']), 'runtime' => ValidatedData::string($process['runtime']),
            'name' => ValidatedData::string($process['name'])], $this->definitions->restartSnapshot($instance)['restart']);
    }
}
