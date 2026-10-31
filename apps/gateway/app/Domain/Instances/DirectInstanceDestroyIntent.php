<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWithdrawalArtifacts;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Records owned dev-removal facts. Swamp owns ordering, effects and recovery. */
final readonly class DirectInstanceDestroyIntent
{
    public function __construct(
        private ProcessOperationAdmission $processes,
        private ScheduleOperationAdmission $schedules,
        private PrepareOwnedDatabaseDestructionAction $databases,
        private RouteWithdrawalArtifacts $routes,
    ) {}

    /** Records intent atomically after source/Route validation.
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function record(array $input): array
    {
        return DB::transaction(fn (): array => $this->recordIntent($input));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function recordIntent(array $input): array
    {
        $stored = InstanceRemoval::query()->whereKey(ValidatedData::string($input['removal_id']))->first();
        if ($stored?->native_intent !== null) {
            return $this->replay($stored, $input);
        }
        $members = InstanceLifecycleInput::members($input);
        $ids = ValidatedData::integerList(array_column($members, 'instance_id'));
        if ($members === [] || count(array_unique($ids)) !== count($ids)
            || ! in_array($input['instance_id'] ?? null, $ids, true)
            || array_any($members, static fn (array $member): bool => $member['environment'] !== 'development' || $member['node_id'] !== $input['node_id'])) {
            throw new ResourceOperationException('instance.destroy_unsupported', 'Direct development removal requires its recorded source Node.', 409);
        }
        foreach ($members as $member) {
            if (! is_string($member['worktree_inventory'] ?? null) || ! is_string($member['origin'] ?? null) || $member['origin'] === '') {
                throw new ResourceOperationException('instance.removal_conflict', 'Removal requires its captured source inventory and origin.', 409);
            }
        }
        // Every owning artifact must be admitted before any desired-state write.
        $databases = array_map(fn (array $member): array => $this->databases->execute(
            Instance::query()->with('node')->findOrFail(ValidatedData::integer($member['instance_id'])),
        ), $members);
        $routeIds = array_values(array_unique([...ValidatedData::integerList(array_values(array_filter(array_column($members, 'route_id')))), ...ValidatedData::integerList($input['additional_route_ids'])]));
        sort($routeIds);
        $attached = Route::query()->whereHas('targets', fn ($query) => $query->whereIn('instance_id', $ids))->orderBy('id')->get();
        if ($attached->modelKeys() !== $routeIds) {
            throw new ResourceOperationException('instance.removal_conflict', 'The complete attached Route inventory changed.', 409);
        }
        $removal = InstanceRemoval::query()->firstOrCreate(['id' => $input['removal_id']], [
            'requested_instance_id' => $input['instance_id'], 'requested_name' => $input['name'],
            'force' => $input['force'], 'inventory_digest' => $input['inventory_digest'], 'total' => count($members),
            'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation,
        ]);
        if ($removal->requested_instance_id !== $input['instance_id'] || $removal->inventory_digest !== $input['inventory_digest']) {
            throw new ResourceOperationException('instance.removal_conflict', 'The admitted removal set changed.', 409);
        }
        $node = Node::query()->findOrFail(ValidatedData::integer($input['node_id']));
        if ($node->user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $node->user) !== 1) {
            throw new ResourceOperationException('instance.destroy_unsupported', 'Development removal requires its configured non-root Node account.', 409);
        }
        $pin = app(AgentDelivery::class)->pin($node);
        $local = [];
        $runtime = ['processes' => [], 'schedules' => []];
        $teardown = [];
        foreach ($members as $member) {
            $instance = Instance::query()->with('project')->findOrFail(ValidatedData::integer($member['instance_id']));
            if ($instance->project_id !== $input['project_id'] || $instance->node_id !== $node->id || ! $instance->placedOnAppDev()) {
                throw new ResourceOperationException('instance.removal_conflict', 'The removal member changed placement.', 409);
            }
            $ownedRoutes = $attached->filter(static fn (Route $route): bool => $route->targets->contains('instance_id', $instance->id))->modelKeys();
            $record = $removal->members()->firstOrCreate(['instance_id' => $instance->id], ['route_ids' => $ownedRoutes, ...Arr::only($member, [
                'position', 'project_id', 'node_id', 'route_id', 'name', 'environment', 'source_layout', 'repository_identity',
                'checkout_path', 'root', 'branch', 'starting_commit', 'source_commit', 'common_repository_path', 'source_identity',
                'linked_worktree_paths', 'source_digest', 'runtime_published', 'origin', 'worktree_inventory',
            ])]);
            if ($record->route_ids !== $ownedRoutes) {
                throw new ResourceOperationException('instance.removal_conflict', 'The recorded Route inventory changed.', 409);
            }
            $local[] = [
                'instance_id' => $instance->id, 'source' => [
                    'root' => $member['source_root'], 'operation' => $removal->id, 'member' => (string) $record->id,
                    'digest' => $member['source_digest'], 'user' => $member['user'], 'group' => $member['group'],
                    'checkout' => $member['checkout_path'], 'common_repository' => $member['common_repository_path'],
                    'layout' => $member['source_layout'], 'source_identity' => $member['source_identity'],
                ],
                'branch' => $member['branch'] ?? '', 'receipt' => hash('sha256', $removal->id."\0".$record->id."\0".ValidatedData::string($member['source_digest'])."\0finalized"),
                'force' => $input['force'] || ! $member['runtime_published'] && $member['starting_commit'] === null,
                'origin' => $member['origin'], 'worktrees' => $member['worktree_inventory'],
                'commit' => $member['source_commit'] ?? '', 'grouping_directory' => dirname(ValidatedData::string($member['checkout_path'])),
                'unresolved' => $member['starting_commit'] === null && ! $member['runtime_published'],
                'independent_worktree' => $input['force'] && $member['source_layout'] === 'worktree'
                    && array_all($members, static fn (array $peer): bool => $peer['source_layout'] !== 'checkout'),
                'own_units' => RemoteDevelopmentInstanceSourceRemoval::ownUnits($instance),
            ];
            foreach (Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->orderBy('id')->get() as $process) {
                $runtime['processes'][] = Arr::only($this->processes->inputFor($process, 'destroy'), ['process_id', 'runtime', 'name', 'vite']);
            }
            foreach (Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)->orderBy('id')->get() as $schedule) {
                $runtime['schedules'][] = Arr::only($this->schedules->inputFor($schedule, 'destroy', cascade: true), ['id', 'group', 'cascade']);
            }
            $steps = $member['run_teardown'] ? app(ProjectLifecycleStepStore::class)->ordered($instance->project, LifecyclePhase::Teardown) : [];
            $teardown[] = ['instance_id' => $instance->id, 'checkout' => $instance->checkout_path,
                'user' => $node->user,
                'steps' => array_map(static fn ($step): array => ['name' => $step->name, 'timeout_seconds' => $step->timeoutSeconds,
                    'sha256' => hash('sha256', $step->command)], $steps)];
        }
        Instance::query()->whereKey($ids)->update(['status' => InstanceState::Removing]);
        // Desired serving state is intent. Associations remain until native withdrawal succeeds.
        Route::query()->whereKey($routeIds)->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
        $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
        $routes = Route::query()->with('targets')->whereKey($routeIds)->orderBy('id')->get();
        $withdrawals = [];
        $nodes = [$node->id];
        foreach ($ids as $id) {
            $target = Instance::query()->with(['node', 'project'])->findOrFail($id);
            $artifact = $this->routes->forDevelopmentRemoval($target,
                $routes->filter(static fn (Route $route): bool => $route->targets->contains('instance_id', $id)));
            $withdrawals[] = $artifact;
            $nodes = [...$nodes, ...$this->participantIds($artifact['nodes']), ...$this->participantIds($artifact['dns'])];
        }
        $nodes = array_values(array_unique($nodes));
        sort($nodes);

        $recorded = [...$input, 'environment' => 'development', 'git_read' => ['$secret' => 'instance.lifecycle_git', 'id' => $input['project_id']],
            'teardown' => ['$secret' => 'instance.destroy_teardown', 'id' => $input['instance_id']],
            'php' => ['$secret' => 'instance.destroy_php', 'id' => $input['instance_id']],
            'direct_destroy' => [
                'node_id' => $node->id, 'node_user' => $node->user, 'agent_sha256' => $pin, 'members' => $local, 'runtime' => $runtime,
                'teardown' => $teardown, 'routes' => $withdrawals, 'publication_node_ids' => $nodes,
                'databases' => $databases,
            ],
        ];
        $removal->update(['native_intent' => $recorded]);

        return $recorded;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function replay(InstanceRemoval $removal, array $input): array
    {
        $saved = $removal->native_intent;
        if ($saved === null || $removal->requested_instance_id !== $input['instance_id']
            || $removal->inventory_digest !== $input['inventory_digest']
            || $saved['node_id'] !== $input['node_id'] || $saved['project_id'] !== $input['project_id']
            || ($saved['force'] !== $input['force'] && ($input['force'] !== true || $removal->status !== InstanceRemovalStatus::Failed))) {
            throw new ResourceOperationException('instance.removal_conflict', 'The saved native removal admission changed.', 409);
        }
        foreach (InstanceLifecycleInput::members($saved) as $member) {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($member['instance_id']));
            if ($instance->status !== InstanceState::Removing || $instance->node_id !== $member['node_id']
                || $instance->project_id !== $member['project_id'] || $instance->checkout_path !== $member['checkout_path']) {
                throw new ResourceOperationException('instance.removal_conflict', 'A saved native removal member changed.', 409);
            }
        }
        if ($input['force'] !== $removal->force) {
            $removal->update(['force' => $input['force']]);
        }
        $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
        $detail = ValidatedData::object($saved['direct_destroy']);
        $detail['routes'] = array_map(static function (array $route): array {
            $php = ValidatedData::object($route['php']);
            $php['configurations'] = (object) ValidatedData::object($php['configurations']);
            $route['php'] = $php;

            return $route;
        }, InstanceLifecycleInput::members(['members' => $detail['routes']]));
        if ($input['force'] === true) {
            $detail['members'] = array_map(static fn (array $member): array => [...$member, 'force' => true],
                InstanceLifecycleInput::members(['members' => $detail['members']]));
        }

        return [...$saved, 'force' => $input['force'], 'direct_destroy' => $detail];
    }

    /** @return list<int> */
    private function participantIds(mixed $artifacts): array
    {
        if (! is_array($artifacts) || ! array_is_list($artifacts)) {
            throw new LogicException('Route withdrawal requires a participant artifact list.');
        }

        return array_map(static fn (mixed $artifact): int => ValidatedData::integer(ValidatedData::object($artifact)['node_id']), $artifacts);
    }
}
