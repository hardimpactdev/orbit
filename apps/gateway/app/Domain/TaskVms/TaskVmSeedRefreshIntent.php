<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Node;
use App\Models\TaskVmImageBuild;

/**
 * Renders the refresh of one host's topology seed as the full workflow input: the image, the seed names, the builder
 * VM and the release to build as public text, and the two credentials the build needs as vault names. No host effects.
 * See docs/cli/tasks/vm-seed-refresh.mmd.
 */
final readonly class TaskVmSeedRefreshIntent
{
    public function __construct(private TaskVmSettings $settings, private TaskVmPromotedImage $images, private SwampSshExecutionInput $ssh,
        private SwampCommandStep $shared, private RepositoryReadAccess $git) {}

    /**
     * Refuses a host that cannot refresh: not a configured, active task VM host; an image build running on it; or a
     * promoted image without a topology seed to start from.
     */
    public function assertCanRefresh(Node $host): TaskVmHost
    {
        if (! $this->settings->enabled || ! $this->settings->hasHost($host->id) || $host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.host_unavailable', 'A seed refresh needs an active, configured task VM host.', 409);
        }
        if (TaskVmImageBuild::query()->where('host_node_id', $host->id)->where('state', TaskVmImageBuildState::Running)->exists()) {
            throw new ResourceOperationException('task_vm.image_build_running', 'An image build is running on this host. Refresh the seed after it ends.', 409);
        }

        return $this->settings->host($host->id);
    }

    /**
     * @return array<string,mixed> one flat array that `TaskVmProviderWorkflowInput::envelope()` splits into its `intent`
     *                             and its `execution_secrets`
     */
    public function capture(Node $host): array
    {
        $location = $this->assertCanRefresh($host);
        $image = $this->images->forHost($host, $location);
        $commit = $this->releaseCommit();
        $club = config('swamp.proof_club_api_key');
        if (! is_string($club) || trim($club) === '') {
            throw new ResourceOperationException('task_vm.seed_refresh_unavailable', 'A seed refresh needs the proof Swamp Club key.', 409);
        }
        $source = GitHubRepository::fromOrigin(config()->string('orbit.cli_releases.repository'));
        if (! $source instanceof GitHubRepository) {
            throw new ResourceOperationException('task_vm.seed_refresh_unavailable', 'The release repository is not a github.com repository.', 409);
        }
        $repository = "https://github.com/{$source->owner}/{$source->name}";
        $access = $this->git->for($repository, ProjectSourceAccess::GitHubApp)->variables['GIT_CONFIG_VALUE_0'] ?? null;
        if (! is_string($access)) {
            throw new ResourceOperationException('task_vm.seed_refresh_unavailable', 'The GitHub App cannot read the release repository.', 409);
        }
        $fingerprint = $image['fingerprint'];
        $current = TaskVmVolumes::seed($fingerprint);

        return [
            'host_node_id' => $host->id, 'node_id' => $host->id,
            'host_ssh_execution' => $this->ssh->forNode($host),
            'image' => ['fingerprint' => $fingerprint, 'recipe_identity' => $image['recipe_identity']],
            // The mapping a claim relies on: it clones `current`, named from the image fingerprint. The refresh swaps what
            // that name holds, so no claim path changes.
            'seed' => ['project' => $location->project, 'pool' => $location->pool, 'current' => $current,
                'building' => TaskVmSeedRefresh::building($fingerprint), 'previous' => TaskVmVolumes::previousSeed($fingerprint), 'size' => TaskVmVolumes::SeedSize],
            'builder' => ['name' => TaskVmSeedRefresh::BuilderName, 'config_json' => TaskVmSeedRefresh::config($location, $fingerprint),
                'checkout' => TaskVmSeedRefresh::Checkout, 'group' => TaskVmSeedRefresh::Group, 'branch' => TaskVmSeedRefresh::Branch,
                'roles' => TaskVmSeedRefresh::Roles],
            'source' => ['repository' => $repository, 'commit' => $commit],
            'execution_secrets' => [
                'git_authorization' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-seed-refresh-git-'.$host->id, $access),
                'proof_club' => $this->shared->executionCopy('orbit-control-inputs', 'swamp-proof-club-api-key', $club),
            ],
        ];
    }

    /** The commit that the Gateway runs: the ready topology holds the release it was built from. */
    private function releaseCommit(): string
    {
        $inventory = GatewayReleaseLayout::fromConfig()->observe();
        $commit = $inventory->current === null ? null : ($inventory->commits[$inventory->current] ?? null);
        if (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new ResourceOperationException('task_vm.seed_refresh_unavailable', 'The Gateway release has no commit to build the seed from.', 409);
        }

        return $commit;
    }
}
