<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceRuntimeDefinitions;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Starts the `instance.rollback` operation (docs/reference/deployments.md#the-rollback-operation). It refuses an
 * Instance that cannot be rolled back, or a release it does not retain, before it records anything; Swamp then executes
 * the rollback from the recorded intent.
 * The native model writer serializes rollback with deployment on the admitted Node.
 */
final readonly class StartInstanceRollbackAction
{
    public function __construct(
        private InstanceDeploymentIntent $intent,
        private ProductionDeployment $releases,
        private StartOperationAction $operations,
        private InstanceRuntimeDefinitions $definitions,
        private RemotePhpPackageManager $php,
    ) {}

    public function execute(Instance $instance, string $release, string $triggeredBy, ?string $requestId): Operation
    {
        return DB::transaction(function () use ($instance, $release, $triggeredBy, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $deployment = $this->intent->snapshot($instance, rollback: true);
            // Retention is admission validation; Swamp checks the release again before activation.
            if (! in_array($release, $this->releases->releases($instance)->releases, true)) {
                throw new ResourceOperationException('rollback.release_not_retained', "The Instance does not retain the release [{$release}]. List its releases with instance:release:list.", 422, details: ['release' => $release]);
            }

            return $this->operations->execute(
                OperationKinds::InstanceRollback,
                OperationKinds::InstanceTarget,
                $instance->id,
                $instance->node_id,
                ['instance_id' => $instance->id, 'release' => $release, 'deployment' => $deployment,
                    'node_id' => $instance->node_id, 'platform' => $instance->node->platform, 'architecture' => $instance->node->architecture,
                    'php_input' => is_string($instance->selected_php_version) ? [
                        'packages' => $this->php->packagesOnlyInputForAppProd($instance->node, [$instance->selected_php_version]),
                        'profile' => $deployment['php_profile'], 'runtime' => $deployment['php_runtime'], 'refresh' => $deployment['php_refresh'],
                    ] : null,
                    'runtime_definitions' => $this->definitions->restartSnapshot($instance)],
                $triggeredBy,
                $requestId,
            );
        });
    }
}
