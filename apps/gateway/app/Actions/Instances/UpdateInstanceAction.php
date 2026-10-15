<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\Deployment\SharedPaths;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Project;

final readonly class UpdateInstanceAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $resolver,
        private InstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    /**
     * Sets the deployment branch, the shared paths, automatic deployment, or several of them, of a production
     * Instance. Setting `auto_deploy` to true also resumes automatic deployment after a rollback paused it
     * ([Automatic deployments](/reference/deployments#automatic-deployments)).
     *
     * @param  list<string>|null  $sharedPaths
     *
     * @throws ResourceOperationException `instance.auto_deploy_gate_unreadable` when the Project has a merge check
     *                                    that the Gateway GitHub App cannot read
     */
    public function execute(Instance $instance, ?string $branch, ?array $sharedPaths = null, bool $sharedPathsProvided = false, ?bool $autoDeploy = null): Instance
    {
        $result = $this->operations->run([$instance->id], function () use ($instance, $branch, $sharedPaths, $sharedPathsProvided, $autoDeploy): Instance {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            if ($branch !== null) {
                $locked->deployment_branch = $branch;
            }
            if ($sharedPathsProvided) {
                $locked->shared_paths = $sharedPaths === null ? null : SharedPaths::validate($sharedPaths);
            }
            if ($autoDeploy === true) {
                $this->assertGateReadable($locked->project);
                $resumedAfter = InstanceDeployment::query()->where('instance_id', $locked->id)->max('id');
                $locked->auto_deploy = true;
                $locked->auto_deploy_resumed_after = is_int($resumedAfter) ? $resumedAfter : 0;
            } elseif ($autoDeploy === false) {
                $locked->auto_deploy = false;
            }
            $locked->save();

            return $locked->refresh();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::InstanceUpdated,
            $result->id,
            InstanceData::fromModel($result)->toArray(),
        );

        return $result;
    }

    /** A merge check is read through the Gateway GitHub App, so a Project that reads through the GitHub CLI cannot gate. */
    private function assertGateReadable(Project $project): void
    {
        if ($project->mergeCheckName() === null) {
            return;
        }

        if ($project->source_access !== ProjectSourceAccess::GitHubApp || ! GitHubRepository::fromOrigin($project->repository_url) instanceof GitHubRepository) {
            throw new ResourceOperationException(
                'instance.auto_deploy_gate_unreadable',
                "Project [{$project->slug}] has the merge check [{$project->mergeCheckName()}], but the Gateway GitHub App cannot read its checks: the Project reads its repository through the GitHub CLI or is not on github.com. Remove the merge check or switch the Project to the GitHub App.",
                409,
            );
        }
    }
}
