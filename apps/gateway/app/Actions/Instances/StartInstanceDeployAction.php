<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\InstanceDeploymentEnvironmentMaterial;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDevelopmentWorkflowInput;
use App\Domain\Instances\Deployment\InstanceRuntimeDefinitions;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Starts the `instance.deploy` operation (docs/reference/deployments.md#the-deploy-operation). It refuses an Instance
 * that cannot be deployed before it records anything; Swamp then executes the recorded intent. A
 * `$commit` pins the deployment to that commit of the branch; without one it deploys the branch's newest commit.
 */
final readonly class StartInstanceDeployAction
{
    public function __construct(
        private InstanceDeploymentIntent $intent,
        private StartOperationAction $operations,
        private AutomaticDeployPolicyWriter $writer,
    ) {}

    public function execute(Instance $instance, string $triggeredBy, ?string $requestId, ?string $commit = null, ?int $callerNodeId = null): Operation
    {
        if ($commit !== null) {
            GitCommitSha::validate($commit);
        }

        return DB::transaction(function () use ($instance, $triggeredBy, $requestId, $commit, $callerNodeId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $development = $instance->placedOnAppDev();
            $members = $development ? null : app(InstanceRuntimeDefinitions::class)->capture($instance);
            $deployment = $this->intent->snapshot($instance);
            if ($development && $deployment['projection_route_id'] !== null) {
                throw new ResourceOperationException('deployment.projection_pending', 'The default Route projection must finish before deployment.', 409);
            }
            $private = ['$secret' => 'instance.deployment', 'id' => $instance->id];
            $input = ['instance_id' => $instance->id, 'deployment' => $deployment,
                'node_id' => $instance->node_id, 'platform' => $instance->node->platform, 'architecture' => $instance->node->architecture,
                'git_read' => $private, 'vp_materialize' => $private,
                'environment_material' => $private, 'definitions_material' => $private,
                ...($commit === null ? [] : ['commit' => $commit])];
            if (! $development) {
                $input['runtime_definitions'] = $members;
                $input['environment'] = app(InstanceDeploymentEnvironmentMaterial::class)->capture($instance);
                $input['php_input'] = $deployment['php'] ? [
                    'packages' => app(RemotePhpPackageManager::class)->packagesOnlyInputForAppProd($instance->node, [ValidatedData::string($deployment['php_version'])]),
                    'profile' => $deployment['php_profile'], 'runtime' => $deployment['php_runtime'], 'refresh' => $deployment['php_refresh'],
                ] : null;
            }
            if ($callerNodeId !== null) {
                $input['caller_node_id'] = $callerNodeId;
            }
            if ($development) {
                $input = app(InstanceDevelopmentWorkflowInput::class)->capture($instance, array_diff_key($input,
                    array_flip(['git_read', 'vp_materialize', 'environment_material', 'definitions_material'])));
            }

            return $this->operations->execute(OperationKinds::InstanceDeploy, OperationKinds::InstanceTarget, $instance->id,
                $instance->node_id, $input, $triggeredBy, $requestId);
        });
    }

    /** Public pins validate the recorded Project identity before admitting the existing operation. */
    public function pinned(Instance $instance, string $triggeredBy, ?string $requestId, string $commit, ?int $callerNodeId = null): Operation
    {
        $project = $instance->project()->firstOrFail();
        if ($project->repository_identity !== GitRepositoryIdentity::derive($project->repository_url)) {
            throw new ResourceOperationException('deployment.repository_mismatch', 'The Project repository identity does not match its origin.', 409);
        }

        return $this->execute($instance, $triggeredBy, $requestId, $commit, $callerNodeId);
    }

    /** The HTTP boundary requires Gateway Node access before admitting automatic origin. */
    public function automatic(Instance $instance, Node $caller, ?string $requestId, string $commit): Operation
    {
        return DB::transaction(function () use ($instance, $caller, $requestId, $commit): Operation {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->writer->assertSwamp($instance);

            return $this->pinned($instance, AutomaticDeployHistory::SwampTrigger, $requestId, $commit, $caller->id);
        });
    }
}
