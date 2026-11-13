<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Routes\NativeRouteCertificateInput;
use App\Domain\Routes\NativeRouteMutationInput;
use App\Domain\Routes\NativeRouteRemovalIntent;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Records owned dev-removal facts. Swamp owns ordering, effects and recovery. */
final readonly class DirectInstanceDestroyIntent
{
    public function __construct(
        private ProcessOperationAdmission $processes,
        private ScheduleOperationAdmission $schedules,
        private PrepareOwnedDatabaseDestructionAction $databases,
    ) {}

    /** Pure preparation, shared by the discarded dry run and fresh transactional admission.
     * @param  array<string,mixed>  $input
     * @return array{array<string,mixed>,\Illuminate\Database\Eloquent\Collection<int,Route>}
     */
    public function prepare(array $input): array
    {
        $this->assertRootAvailable($input);
        $members = InstanceLifecycleInput::members($input);
        $stored = InstanceRemoval::query()->whereKey(ValidatedData::string($input['removal_id']))->first();
        if ($stored?->native_intent !== null) {
            return [[...$this->assertReplayAvailable($stored, $input), 'force' => $input['force']], new \Illuminate\Database\Eloquent\Collection];
        }
        $ids = $this->memberIds($input, $members);
        $databases = $this->databases->snapshot(Instance::query()->with('node')->findOrFail(ValidatedData::integer($input['instance_id'])), $ids);
        [, $attached] = $this->routes($input, $members, $ids);
        [$beforeSites, $previousRoutes] = $this->routeInputs($attached);
        if ($stored !== null && ($stored->requested_instance_id !== $input['instance_id'] || $stored->inventory_digest !== $input['inventory_digest'])) {
            throw new ResourceOperationException('instance.removal_conflict', 'The admitted removal set changed.', 409);
        }
        $node = $this->node($input);
        [$runtime, $nativeProcesses, $teardown] = $this->runtimeInputs($input, $members, $node);
        // Withdrawal has no URL/environment writes. Its capture renders prospective sites and stages no execution copy.
        $captured = app(NativeRouteMutationInput::class)->capture($previousRoutes, [], [], [], $beforeSites,
            app(DevelopmentSiteRepository::class)->all(),
            array_values(array_unique(array_merge(...array_map(static fn (array $row): array => $row['intent']['node_ids'], $previousRoutes)))),
            static fn (\Closure $render): array => $render());
        $ssh = app(SwampSshExecutionInput::class)->forNode($node);

        return [[...$input,
            'participant_node_ids' => array_values(array_unique([$node->id, ...$captured['participant_node_ids']])),
            'environment' => 'development',
            'native_destroy' => ['ssh_execution' => $ssh, 'native_runtime' => [...$runtime, 'processes' => $nativeProcesses],
                'route_mutations' => $captured['route_mutations'], 'execution_secrets' => $captured['execution_secrets']],
            'direct_destroy' => ['node_id' => $node->id, 'node_user' => $node->user, 'runtime' => $runtime,
                'teardown' => $teardown, 'routes' => [], 'publication_node_ids' => $captured['participant_node_ids'],
                'database' => ['owner_instance_id' => $input['instance_id'], ...$databases]],
        ], $attached];
    }

    /** Builds and records fresh intent in the existing transaction after Schedule removal.
     * @param  array<string,mixed>  $input  The original request/source envelope; no dry-run result is accepted.
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        return DB::transaction(fn (): array => $this->recordIntent($input));
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function recordIntent(array $input): array
    {
        [$input, $attached] = $this->prepare($input);
        $members = InstanceLifecycleInput::members($input);
        $stored = InstanceRemoval::query()->whereKey(ValidatedData::string($input['removal_id']))->first();
        if ($stored?->native_intent !== null) {
            return $this->replay($stored, $input);
        }
        $ids = ValidatedData::integerList(array_column($members, 'instance_id'));
        $routeIds = $attached->modelKeys();
        $direct = ValidatedData::object($input['direct_destroy']);
        $database = Arr::except(ValidatedData::object($direct['database']), ['owner_instance_id']);
        $receipt = app(RecordDatabaseOperationAction::class)->prepare('instance.destroy', 'instance',
            ValidatedData::integer($input['instance_id']), ValidatedData::integer($input['node_id']), $database, []);
        $input['direct_destroy'] = [...$direct, 'database' => [
            'owner_instance_id' => $input['instance_id'], 'intent_id' => ValidatedData::integer($receipt->input['intent_id']), ...$database,
        ]];
        $removal = InstanceRemoval::query()->firstOrCreate(['id' => $input['removal_id']], [
            'requested_instance_id' => $input['instance_id'], 'requested_name' => $input['name'],
            'force' => $input['force'], 'inventory_digest' => $input['inventory_digest'], 'total' => count($members),
            'status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::SourcePreparation,
        ]);
        if ($removal->requested_instance_id !== $input['instance_id'] || $removal->inventory_digest !== $input['inventory_digest']) {
            throw new ResourceOperationException('instance.removal_conflict', 'The admitted removal set changed.', 409);
        }
        $node = $this->node($input);
        foreach ($members as $member) {
            $instance = $this->member($member, $input, $node);
            $ownedRoutes = $attached->filter(static fn (Route $route): bool => $route->targets->contains('instance_id', $instance->id))->modelKeys();
            $record = $removal->members()->firstOrCreate(['instance_id' => $instance->id], ['route_ids' => $ownedRoutes, ...Arr::only($member, [
                'position', 'project_id', 'node_id', 'route_id', 'name', 'environment', 'source_layout', 'repository_identity',
                'checkout_path', 'root', 'branch', 'starting_commit', 'source_commit', 'common_repository_path', 'source_identity',
                'linked_worktree_paths', 'source_digest', 'runtime_published', 'origin', 'worktree_inventory',
            ])]);
            if ($record->route_ids !== $ownedRoutes) {
                throw new ResourceOperationException('instance.removal_conflict', 'The recorded Route inventory changed.', 409);
            }
        }
        Instance::query()->whereKey($ids)->update(['status' => InstanceState::Removing]);
        Route::query()->whereKey($routeIds)->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
        $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null, 'native_intent' => $input]);

        return $input;
    }

    /** Stored runtime and teardown description; no execution or model write.
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $members
     * @return array{array{processes:list<array<string,mixed>>,schedules:list<array<string,mixed>>},list<array<string,mixed>>,list<array<string,mixed>>}
     */
    private function runtimeInputs(array $input, array $members, Node $node): array
    {
        $runtime = ['processes' => [], 'schedules' => []];
        $nativeProcesses = [];
        $teardown = [];
        foreach ($members as $member) {
            $instance = $this->member($member, $input, $node);
            foreach (Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->orderBy('id')->get() as $process) {
                $control = ($input['container_destroy'] ?? null) === null
                    ? $this->processes->inputFor($process, 'destroy')
                    : ['process_id' => $process->id, 'runtime' => 'container', 'name' => $process->name];
                $nativeProcesses[] = Arr::only($control, ['process_id', 'runtime', 'name', 'on_demand', 'directory', 'vite']);
                $runtime['processes'][] = Arr::only($control, ['process_id', 'runtime', 'name', 'vite']);
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

        return [$runtime, $nativeProcesses, $teardown];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function replay(InstanceRemoval $removal, array $input): array
    {
        $saved = $this->assertReplayAvailable($removal, $input);
        if ($input['force'] !== $removal->force) {
            $removal->update(['force' => $input['force']]);
        }
        $removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);

        return [...$saved, 'force' => $input['force']];
    }

    /** @param array<string,mixed> $input */
    private function assertRootAvailable(array $input): void
    {
        app(ContainerInstanceDestroy::class)->assertAvailable(Instance::query()->lockForUpdate()->findOrFail(ValidatedData::integer($input['instance_id'])), $input['force'] === true);
        $members = InstanceLifecycleInput::members($input);
        if (Operation::query()->whereIn('kind', ['instance.destroy'])
            ->where('target_type', 'instance')->whereIn('target_id', array_column($members, 'instance_id'))
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'A removal root already owns this Instance.', 409);
        }
        // A restore holds the Instance from its start until its run has ended, timed-out runs included.
        if (Operation::query()->where('kind', 'instance.restore')->where('lock_key', OperationStore::lockKey('instance', ValidatedData::integer($input['instance_id'])))->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'A restore owns this Instance.', 409);
        }
    }

    /** @param array<string,mixed> $input
     * @param  list<array<string,mixed>>  $members
     * @return list<int>
     */
    private function memberIds(array $input, array $members): array
    {
        $ids = ValidatedData::integerList(array_column($members, 'instance_id'));
        if ($members === [] || count(array_unique($ids)) !== count($ids)
            || ! in_array($input['instance_id'] ?? null, $ids, true)
            || array_any($members, static fn (array $member): bool => $member['environment'] !== 'development' || $member['node_id'] !== $input['node_id'])) {
            throw new ResourceOperationException('instance.destroy_unsupported', 'Direct development removal requires its recorded source Node.', 409);
        }
        foreach ($members as $member) {
            if (! ($input['source_pending'] ?? false) && (! is_string($member['worktree_inventory'] ?? null) || ! is_string($member['origin'] ?? null) || $member['origin'] === '')) {
                throw new ResourceOperationException('instance.removal_conflict', 'Removal requires its captured source inventory and origin.', 409);
            }
        }

        return $ids;
    }

    /** @param array<string,mixed> $input
     * @param  list<array<string,mixed>>  $members
     * @param  list<int>  $ids
     * @return array{list<int>,\Illuminate\Database\Eloquent\Collection<int,Route>}
     */
    private function routes(array $input, array $members, array $ids): array
    {
        $routeIds = array_values(array_unique([...ValidatedData::integerList(array_values(array_filter(array_column($members, 'route_id')))), ...ValidatedData::integerList($input['additional_route_ids'])]));
        sort($routeIds);
        $attached = Route::query()->whereHas('targets', fn ($query) => $query->whereIn('instance_id', $ids))->orderBy('id')->get();
        if ($attached->modelKeys() !== $routeIds) {
            throw new ResourceOperationException('instance.removal_conflict', 'The complete attached Route inventory changed.', 409);
        }

        return [$routeIds, $attached];
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int,Route> $attached
     * @return array{Collection<int,DevelopmentSite>,list<array{route_id:int,domain:string,intent:array{node_ids:list<int>,certificates:list<array<string,mixed>>},sites:list<DevelopmentSite>,url_writes:list<array{node_id:int,instance_id:int,name:string,input:array<string,mixed>}>}>}
     */
    private function routeInputs(\Illuminate\Database\Eloquent\Collection $attached): array
    {
        $sites = app(DevelopmentSiteRepository::class);
        $beforeSites = clone $sites->all();
        $previousRoutes = [];
        foreach ($attached as $route) {
            $removalInventory = app(NativeRouteRemovalIntent::class)->capture($route);
            // A first publication can reach the Node before its probe fails, without setting sites_published.
            // Capture its possible publication as well as absence; withdrawal accepts either actual state.
            $routeSites = $sites->routeSites($route);
            $beforeSites->push(...$routeSites);
            $leaves = [];
            $nodeIds = [];
            foreach ($removalInventory['nodes'] as $participant) {
                $target = Node::query()->findOrFail($participant['node_id']);
                $nodeIds[] = $target->id;
                foreach ($participant['certificate_scopes'] as $scope) {
                    $leaves[] = app(NativeRouteCertificateInput::class)->scopeInput($target, $scope);
                }
            }
            foreach ($routeSites as $site) {
                if (! $site->publicListener) {
                    $nodeIds[] = $site->nodeId;
                    $leaves[] = app(NativeRouteCertificateInput::class)->scopeInput(Node::query()->findOrFail($site->nodeId), $site->certificateScope ?? $site->scope);
                }
            }
            $previousRoutes[] = ['route_id' => $route->id, 'domain' => $route->domain,
                'intent' => ['node_ids' => array_values(array_unique($nodeIds)), 'certificates' => array_values(array_unique($leaves, SORT_REGULAR))], 'sites' => $routeSites, 'url_writes' => []];
        }
        $beforeSites = $beforeSites->unique(static fn (DevelopmentSite $site): string => $site->addressKey())->values();

        return [$beforeSites, $previousRoutes];
    }

    /** @param array<string,mixed> $input */
    private function node(array $input): Node
    {
        $node = Node::query()->findOrFail(ValidatedData::integer($input['node_id']));
        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('route.withdrawal_node_unavailable', 'Withdrawal requires its active admitted Node.', 409);
        }
        if ($node->user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $node->user) !== 1) {
            throw new ResourceOperationException('instance.destroy_unsupported', 'Development removal requires its configured non-root Node account.', 409);
        }

        return $node;
    }

    /** @param array<string,mixed> $member
     * @param  array<string,mixed>  $input
     */
    private function member(array $member, array $input, Node $node): Instance
    {
        $instance = Instance::query()->with('project')->findOrFail(ValidatedData::integer($member['instance_id']));
        if ($instance->project_id !== $input['project_id'] || $instance->node_id !== $node->id || ! $instance->placedOnAppDev()) {
            throw new ResourceOperationException('instance.removal_conflict', 'The removal member changed placement.', 409);
        }

        return $instance;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function assertReplayAvailable(InstanceRemoval $removal, array $input): array
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

        return $saved;
    }
}
