<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Proof\ProofSettings;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Cache;

/**
 * Admits the refresh of one task VM host's image (docs/cli/tasks/vm-image-refresh.mmd) as one run on the host: one VM from the
 * current image, one Ansible run that converges everything, then the image and its seed are published and promoted. The intent
 * holds everything the run needs; no step calls the Gateway.
 */
final readonly class TaskVmImageRefreshIntent
{
    public const string Kind = 'task_vm.image_refresh';

    /** The Composer and Bun projects of the warm checkout, as the Project's setup steps install them. */
    private const array Composer = ['.', 'apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    private const array Bun = ['apps/web', 'apps/pi-server', 'apps/desktop'];

    /** Where the proof Swamp Club key lives on a task VM; the nested Gateway reads it through a disk device (swamp/ansible/task-vm-role.yml). */
    public const string ClubKey = '/etc/orbit/swamp-club-api-key';

    public function __construct(private StartOperationAction $operations, private TaskVmSettings $settings, private TaskVmPromotedImage $images,
        private SwampSshExecutionInput $ssh, private ProofSettings $proof, private RecipeImageBuilds $recipes, private SwampCommandStep $shared,
        private StorageRootResolver $roots, private NodeSettingsNormalizer $nodeSettings) {}

    /**
     * The refresh that is open on `$host`, if any: a second admission returns it.
     *
     * @phpstan-impure
     */
    public static function open(Node|int $host): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'task_vm_host')->where('target_id', $host instanceof Node ? $host->id : $host)
            ->whereNotIn('status', OperationStatus::terminalValues())->first();
    }

    /** Refuses a host that cannot refresh: not a configured, active task VM host, or an image build running on it. */
    public function assertCanRefresh(Node $host): TaskVmHost
    {
        if (! $this->settings->enabled || ! $this->settings->hasHost($host->id) || $host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.host_unavailable', 'A refresh needs an active, configured task VM host.', 409);
        }
        if (TaskVmImageBuild::query()->where('host_node_id', $host->id)->where('state', TaskVmImageBuildState::Running)->exists()) {
            throw new ResourceOperationException('task_vm.image_build_running', 'An image build is running on this host. Refresh after it ends.', 409);
        }

        return $this->settings->host($host->id);
    }

    public function start(Node $host, string $caller, ?string $request = null): Operation
    {
        if (($open = self::open($host)) instanceof Operation) {
            return $open;
        }
        $lock = Cache::lock('task-vm-image-refresh-'.$host->id, 120);
        if (! $lock->get()) {
            return self::open($host) ?? throw new ResourceOperationException('operation.target_busy', 'Another refresh of this host is being admitted.', 409);
        }
        try {
            return self::open($host) ?? $this->operations->execute(self::Kind, 'task_vm_host', $host->id, $host->id, $this->input($host),
                $caller, $request, lockKey: OperationStore::lockKey('task_vm_host', $host->id));
        } finally {
            $lock->release();
        }
    }

    /**
     * The full intent of the run. The VM, image and seed names follow the host's image alias, so a rerun after a failure finds and
     * replaces what the failed run left.
     *
     * @return array<string,mixed>
     */
    public function input(Node $host): array
    {
        $location = $this->assertCanRefresh($host);
        $image = $this->images->forHost($host, $location);
        $commit = $this->releaseCommit();
        $club = config('swamp.proof_club_api_key');
        if (! is_string($club) || trim($club) === '') {
            throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'The refresh needs the proof Swamp Club key for the nested Gateway.', 503);
        }
        $architecture = MachineRecipe::architecture($host->architecture)
            ?? throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'The host architecture is unsupported.', 409);
        $recipe = $this->recipes->currentRecipe();
        $recipe->profile('base', $architecture);
        $pi = $recipe->tools['pi']->artifacts[$architecture] ?? throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'The recipe holds no Pi server for this architecture.', 409);
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($query) => $query
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $jump = $this->ssh->forNode($host);
        $identity = $this->ssh->identity();
        $large = TaskVmSize::Large->resources();
        $vm = $host->name.'-refresh';

        return [
            'commit' => $commit, 'recipe_identity' => $recipe->identity,
            'intent' => [
                'host' => ['node_id' => $host->id, 'ssh' => $jump, 'project' => $location->project, 'pool' => $location->pool, 'network' => $location->network,
                    'alias' => $location->imageAlias, 'previous_alias' => $location->imageAlias.'-previous', 'candidate_alias' => $location->imageAlias.'-candidate',
                    'seed_prefix' => TaskVmVolumes::SeedPrefix, 'current' => $image['fingerprint']],
                // The refresh VM holds the nested fleet, so it has the resources of a large task VM. Its root disk is the host's base disk:
                // a published image is as large as that disk, and no VM can be smaller than its image.
                'vm' => ['name' => $vm, 'seed_clone' => $vm.'-seed', 'cpus' => $large->cpus, 'memory' => $large->memory, 'disk' => $location->disk,
                    'launch_config' => TaskVmLaunchConfig::for($identity['public_key']), 'club_key' => self::ClubKey],
                'converge' => [
                    'project' => $location->project, 'jump' => $jump,
                    'ssh' => ['identity_file' => $identity['identity_file'], 'target' => ['host' => '', 'user' => 'orbit', 'port' => 22, 'host_keys' => []]],
                    'profile' => 'base', 'image' => true,
                    'nested' => ['subnet' => TaskVmReadyCopiesIntent::Subnet, 'interface' => TaskVmReadyCopiesIntent::Network],
                    'recipe' => json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR),
                    'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']),
                    'pi' => ['sha256' => $pi->digest, 'source' => ['ssh' => $this->ssh->forNode($gateway), 'path' => config()->string('orbit.home').'/sandbox-artifacts/'.$pi->location]],
                    'boot' => ['authorized_key' => $identity['public_key']],
                    'checkout' => $this->checkout($commit),
                    // The outer VM joins the nested WireGuard network as its operator; the hub is the nested Gateway.
                    'fleet' => [...TaskVmReadyCopiesIntent::fleet(), 'operator' => ['address' => '10.45.0.10', 'hub' => '10.45.0.1', 'network' => '10.45.0.0/24']],
                ],
            ],
            'execution_secrets' => ['club' => $this->shared->executionCopy('orbit-control-inputs', 'swamp-proof-club-api-key', $club)],
        ];
    }

    /**
     * The warm checkout of the slots' Project at the release commit: the path is the one a slot's workspace Instance names, `<apps>/<slug>/workspace`
     * of the task VM account.
     *
     * @return array<string,mixed>
     */
    private function checkout(string $commit): array
    {
        $project = Project::query()->findOrFail($this->proof->projectId());
        $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        $path = $this->roots->resolveApps($this->nodeSettings->fromStored(null), $account)->append($project->slug)->append(TaskVmWorkspaceGuard::WorkspaceCheckout);

        return ['path' => $path->value, 'repository' => $project->repository_url, 'commit' => $commit, 'composer' => self::Composer, 'bun' => self::Bun];
    }

    /** The commit that the Gateway runs: the refreshed image holds the playbooks and pins of the release it serves. */
    private function releaseCommit(): string
    {
        $inventory = GatewayReleaseLayout::fromConfig()->observe();
        $commit = $inventory->current === null ? null : ($inventory->commits[$inventory->current] ?? null);
        if (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'The Gateway release has no commit to refresh the image to.', 409);
        }

        return $commit;
    }
}
