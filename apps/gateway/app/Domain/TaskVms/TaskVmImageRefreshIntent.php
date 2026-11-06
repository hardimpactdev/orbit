<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofSettings;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskWait;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Admits the refresh of one task VM host's image and seed (stage 6, docs/reference/pipeline/6-task-image-seed-refresh.mmd) as
 * one run. The refresh VM is a task VM like any other, so the admission is the one of `tasks.prep`: a group of its own, in
 * the proof Project, placed on this host only, with the whole intent captured in the admission transaction of provision.
 * No step of the run calls the Gateway.
 */
final readonly class TaskVmImageRefreshIntent
{
    public const string Kind = 'task_vm.image_refresh';

    /** The nested fleet is built or started in the refresh VM, and its disk becomes the new seed. */
    public const string SeedBuild = 'build';

    /** The image part alone: no fleet is built and no ready copies are saved, and the seed that the refresh VM cloned is copied as it is. */
    public const string SeedKeep = 'keep';

    /**
     * The room a refresh leaves on its host for one more large task VM: proofs and tasks come before a refresh for capacity.
     */
    public static function reservedSlots(): int
    {
        return TaskVmSize::Large->slots();
    }

    public function __construct(private TaskProvisionIntent $provision, private TaskVmSettings $settings, private TaskVmPromotedImage $images,
        private SwampSshExecutionInput $ssh, private ProofSettings $proof, private RecipeImageBuilds $recipes,
        private TaskVmReadyCopiesIntent $copies, private TaskVmFleetBuildIntent $fleet, private TaskVmCapacity $capacity) {}

    /**
     * The refresh that is open on `$host`, if any: a second admission returns it.
     *
     * @phpstan-impure
     */
    public static function open(Node|int $host): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->where('input->refresh->host_node_id', $host instanceof Node ? $host->id : $host)
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

    public function start(Node $host, string $caller, ?string $request, string $seed = self::SeedBuild): Operation
    {
        if (! in_array($seed, [self::SeedBuild, self::SeedKeep], true)) {
            throw new ResourceOperationException('task_vm.seed_invalid', 'The seed of a refresh is build or keep.', 422);
        }
        if (($open = self::open($host)) instanceof Operation) {
            return $open;
        }
        $lock = Cache::lock('task-vm-image-refresh-'.$host->id, 120);
        if (! $lock->get()) {
            return self::open($host) ?? throw new ResourceOperationException('operation.target_busy', 'Another refresh of this host is being admitted.', 409);
        }
        try {
            return self::open($host) ?? $this->admit($host, $caller, $request, $seed);
        } finally {
            $lock->release();
        }
    }

    private function admit(Node $host, string $caller, ?string $request, string $seed): Operation
    {
        $location = $this->assertCanRefresh($host);
        $image = $this->images->forHost($host, $location);
        $commit = $this->releaseCommit();
        // A host that can never hold the refresh VM and one more large task VM can never refresh.
        if ($location->maxVms < TaskVmSize::Large->slots() + self::reservedSlots()) {
            throw new ResourceOperationException('task_vm.host_unavailable', 'A refresh needs a host with room for two large task VMs.', 409);
        }
        // Checked before the group exists, so a refused refresh creates nothing; the placement checks it again under its lock.
        if (! $this->hasRoom($host)) {
            throw new ResourceOperationException('tasks.capacity_unavailable', 'A refresh needs room on its host for the refresh VM and one more large task VM; try again when a task VM ends.', 409, details: ['reason' => TaskWait::Capacity->value]);
        }
        $group = app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->proof->projectId(), 'Refresh the task image of '.$host->name,
            'Bring the task image and the topology seed of '.$host->name.' up to commit '.$commit.'.', TaskGroupStatus::Todo, [new TaskInputData('Refresh the image',
                'The Swamp run task_vm.image_refresh refreshes the image and the seed of the host.', [['id' => 'refreshed', 'type' => 'review', 'description' => 'The host promotes a new image and seed.']])]));
        try {
            return $this->provision->admit($group, $commit, self::Kind, fn (array $input, Task $group, Instance $instance, TaskVm $vm): array => $this->input($input, $group, $instance, $vm, $host, $location, $image, $commit, $seed), $caller, $request, $host->id, TaskVmSize::Large, self::reservedSlots());
        } catch (\Throwable $exception) {
            app(CancelTaskGroupAction::class)->execute($group);

            throw $exception;
        }
    }

    /** Whether `$host` has room for the refresh VM and one more large task VM. */
    private function hasRoom(Node $host): bool
    {
        $hosts = array_values(array_filter($this->capacity->activeHosts(), static fn (array $entry): bool => $entry[0]->nodeId === $host->id));

        return ($this->capacity->freeSlots($hosts)[$host->id] ?? 0) >= TaskVmSize::Large->slots() + self::reservedSlots();
    }

    /**
     * @param  array<string,mixed>  $input  the provision intent
     * @param  array{fingerprint:string,recipe_identity:string}  $image  the image the host serves now
     * @return array<string,mixed>
     */
    private function input(array $input, Task $group, Instance $instance, TaskVm $vm, Node $host, TaskVmHost $location, array $image, string $commit, string $seed): array
    {
        // A published image is as large as the root disk of its VM, and no VM can be smaller than its image. The refresh VM
        // keeps the root disk of the host's base VM, so the image it publishes still fits a normal VM.
        $input['create'] = [...ValidatedData::object($input['create']), 'disk' => $location->disk];
        // The guest's host key is pinned only by the VM step of the run; the run fills it into these targets.
        $pinned = app(PendingSshHostKey::class)->during((int) $instance->node_id, fn (): array => ['ready_copies' => $this->copies->provisioning($vm), 'fleet_build' => $this->fleet->provisioning($vm, (string) $instance->checkout_path)]);
        $candidate = $location->imageAlias.'-refresh-'.$group->id;
        $recipeIdentity = $this->recipes->currentRecipe()->identity;
        $jump = $this->ssh->forNode($host);
        // The provision converges the outer VM, so its converge intent also holds the pins that the smoke of the image checks.
        $converge = $input['converge'] ?? throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'The refresh VM needs the converge intent of a topology VM.', 409);
        $converge = ValidatedData::object($converge);

        return [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $input['node_id'],
            'execution_secrets' => $input['execution_secrets'] ?? [],
            'provision' => array_diff_key($input, ['execution_secrets' => true]),
            'refresh' => [
                'host_node_id' => $host->id, 'host_ssh_execution' => $jump, 'commit' => $commit, 'seed' => $seed,
                'image' => $image, 'recipe_identity' => $recipeIdentity,
                'vm' => ['name' => $vm->name, 'project' => $location->project, 'pool' => $location->pool, 'seed_volume' => $vm->name.'-'.TaskVmVolumes::SeedDevice],
                'names' => ['alias' => $location->imageAlias, 'candidate_alias' => $candidate, 'seed_prefix' => TaskVmVolumes::SeedPrefix],
                // What the image build gives its smoke and promote children, so the refresh boot-checks and promotes as a build does.
                'candidate' => [
                    'project' => $location->project, 'jump' => $jump, 'candidate_alias' => $candidate, 'base_alias' => $location->imageAlias,
                    // The image is published from a builder cloned from the image the refresh VM started from, so image and seed share their origin.
                    'builder' => 'refresh-build-'.$group->id, 'source' => $image['fingerprint'], 'identity' => $recipeIdentity,
                    'previous_alias' => $location->imageAlias.'-previous', 'smoke' => 'refresh-check-'.$group->id, 'network' => $location->network,
                    'cpus' => $location->cpus, 'memory' => $location->memory, 'disk' => $location->disk,
                    'launch_config' => TaskVmLaunchConfig::for($jump['public_key']),
                    'ssh' => ['identity_file' => $jump['identity_file'], 'target' => ['host' => '', 'user' => 'orbit', 'port' => 22, 'host_keys' => []]],
                    'profile' => 'base', 'image' => true, 'nfs' => null,
                    'recipe' => $converge['recipe'], 'vp_lock' => $converge['vp_lock'], 'pi' => $converge['pi'],
                ],
                'ready_copies' => Arr::except($pinned['ready_copies'], ['execution_secrets']),
                'fleet_build' => Arr::except($pinned['fleet_build'], ['execution_secrets']),
            ],
        ];
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
