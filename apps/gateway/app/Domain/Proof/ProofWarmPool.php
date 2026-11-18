<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStart;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProofWarmCopy;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The pool of warm proof copies (docs/cli/proof/run.mdx#warm-copies, ADR 0260): copies of the proof default whose create has ended, which
 * a proof takes first. Each is a task copy of a group of its own, made by the same start as a proof copy, and holds its place while it
 * waits (ProofCopyHosts::copies). A proof that takes one deletes its row, and the group is its run's from then on. keep() replaces the
 * warm copies of an older seed and refills the pool; drain() and resume() are proof:pool:drain and proof:pool:resume.
 */
final readonly class ProofWarmPool
{
    /** The Activity trigger of the starts and cancels that the pool makes. */
    public const string Trigger = 'proof-pool';

    /** The Gateway setting that holds a drain until the resume. */
    public const string DrainKey = 'proof.warm_pool_drained';

    public function __construct(private ProofSettings $settings, private SettingRepository $store) {}

    public function drained(): bool
    {
        return $this->store->get(self::scope(), self::DrainKey) !== null;
    }

    /**
     * proof:pool:drain: records the drain and destroys every idle warm copy. Running proofs keep their copies.
     *
     * @return list<int> the task groups whose copy is being destroyed
     */
    public function drain(): array
    {
        // Under the pool lock: a refill whose reads run now sees the drain in its create transaction, or its copies are dropped below.
        DB::transaction(function (): void {
            $this->lock();
            $this->store->put(self::scope(), self::DrainKey, now()->toIso8601String());
        });

        return $this->drop(ValidatedData::integerList(ProofWarmCopy::query()->whereNull('dropped_at')->pluck('id')->all()));
    }

    /**
     * proof:pool:resume: ends a drain and refills the pool.
     *
     * @return list<int> the task groups of the new warm copies
     */
    public function resume(): array
    {
        $this->store->delete(self::scope(), self::DrainKey);

        return $this->keep();
    }

    /**
     * What proof:pool:drain and proof:pool:resume answer: whether the pool is drained, the warm copies that exist (also those whose
     * destroy runs) and the copies of running proofs, by Instance id.
     *
     * @return array{drained: bool, warm_copies: list<int>, proof_copies: list<int>}
     */
    public function state(): array
    {
        $copies = ProofCopyHosts::copies()->orderBy('id')->get(['id', 'task_workspace_owner_id']);
        $warm = ProofWarmCopy::query()->pluck('task_id')->all();

        return ['drained' => $this->drained(),
            'warm_copies' => ValidatedData::integerList($copies->whereIn('task_workspace_owner_id', $warm)->pluck('id')->values()->all()),
            'proof_copies' => ValidatedData::integerList($copies->whereNotIn('task_workspace_owner_id', $warm)->pluck('id')->values()->all())];
    }

    /**
     * The warm copies that a proof may take now on each Node, by Node id: each is ready (ProofCopies::copyState) and from the proof
     * default's current seed.
     *
     * @return array<int, int>
     */
    public function takeable(): array
    {
        $source = app(ProofSeeds::class)->source();
        if ($source === null) {
            return [];
        }

        return ProofWarmCopy::query()->whereNull('dropped_at')->orderBy('id')->get()
            ->filter(fn (ProofWarmCopy $warm): bool => $this->current($warm, $source) && $this->ready($warm))
            ->countBy('node_id')->all();
    }

    /** Takes a warm copy on the Node for a proof run, in the admission transaction: its group, or null when the Node has none to take. */
    public function take(int $nodeId): ?Task
    {
        $source = app(ProofSeeds::class)->source();
        if ($source === null) {
            return null;
        }
        $warm = ProofWarmCopy::query()->where('node_id', $nodeId)->whereNull('dropped_at')->orderBy('id')->lockForUpdate()->get()
            ->first(fn (ProofWarmCopy $warm): bool => $this->current($warm, $source) && $this->ready($warm));
        if ($warm === null) {
            return null;
        }
        $warm->delete();

        return Task::topLevel()->findOrFail($warm->task_id);
    }

    /**
     * Keeps the pool: forgets each dropped warm copy whose copy is gone and asks again for the destroy of the others; drops each warm
     * copy of an older seed, whose copy failed, or beyond the pool size, and every one while the pool is drained; then starts new warm
     * copies on Nodes with a free place until the pool holds `proof.warm_copies`. A Node whose task copies reach `orbit.tasks.copies_max`
     * takes none: a warm copy counts against that cap. It never waits for a place, and starts none while a proof waits for its admission.
     *
     * @return list<int> the task groups of the new warm copies
     */
    public function keep(): array
    {
        $source = app(ProofSeeds::class)->source();
        $size = $this->drained() || ! $this->settings->executionEnabled() ? 0 : $this->settings->warmCopies();
        $this->forget();
        $this->drop(ValidatedData::integerList(ProofWarmCopy::query()->whereNotNull('dropped_at')->pluck('id')->all()));
        $warm = ProofWarmCopy::query()->whereNull('dropped_at')->orderBy('id')->get();
        $drop = $warm->filter(fn (ProofWarmCopy $copy): bool => $source === null || ! $this->current($copy, $source)
            || ProofCopies::copyState(Task::topLevel()->with('taskable')->findOrFail($copy->task_id)) === 'failed');
        $kept = $warm->diffKeys($drop);
        $this->drop(ValidatedData::integerList([...$drop->pluck('id')->all(), ...$kept->slice($size)->pluck('id')->all()]));
        $need = $size - min($size, $kept->count());
        $project = Project::query()->find($this->settings->projectId());
        if ($need === 0 || $source === null || $project === null || ! ProofCopies::runs($project) || ProofRunIntent::waitsForAdmission()) {
            return [];
        }
        $copies = app(ProofCopies::class);
        $places = array_filter($copies->freePlaces($project, $copies->read()),
            static fn (int $nodeId): bool => TaskStart::live($nodeId) < Config::integer('orbit.tasks.copies_max'), ARRAY_FILTER_USE_KEY);
        if ($places === []) {
            return [];
        }
        $read = app(TaskStart::class)->read($project, array_keys($places));
        $places = array_intersect_key($places, $read['hosts']);

        return DB::transaction(function () use ($places, $need, $read, $source): array {
            // The reads above are slow. Under the pool lock, a drain or a proof that came meanwhile still stops the refill, and the
            // copy cap is counted as admitRead counts it.
            $this->lock();
            if ($this->drained() || ProofRunIntent::waitsForAdmission()) {
                return [];
            }
            $nodes = Node::query()->findMany(array_keys($places))->keyBy('id');
            $groups = [];
            foreach (ProofCopies::spread($places, $need) as $nodeId) {
                if (TaskStart::live($nodeId) >= Config::integer('orbit.tasks.copies_max')) {
                    continue;
                }
                $group = $this->group();
                app(TaskStart::class)->admitRead($group, self::Trigger, null, $read, $nodes->get($nodeId));
                ProofWarmCopy::query()->create(['task_id' => $group->id, 'node_id' => $nodeId,
                    'seed_commit' => $source->seed_commit, 'seed_taken_at' => $source->seed_taken_at]);
                $groups[] = $group->id;
            }

            return $groups;
        });
    }

    /**
     * Drops warm copies: marks each dropped and cancels its group with discard, which destroys the copy. The row stays until the copy is
     * gone (forget), so the copy holds its place until then. A copy that an Operation still holds, such as its create, is asked again
     * at the next keep().
     *
     * @param  list<int>  $ids
     * @return list<int> the task groups
     */
    private function drop(array $ids): array
    {
        $groups = [];
        foreach ($ids as $id) {
            // Read again under the row lock: a proof that took the copy meanwhile deleted its row, and its group is the run's now.
            $group = DB::transaction(static function () use ($id): ?Task {
                $warm = ProofWarmCopy::query()->lockForUpdate()->find($id);
                $warm?->update(['dropped_at' => $warm->dropped_at ?? now()]);

                return $warm === null ? null : Task::topLevel()->find($warm->task_id);
            });
            if ($group === null) {
                continue;
            }
            $groups[] = $group->id;
            try {
                app(ProofGroup::class)->discard($group, self::Trigger);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'operation.target_busy') {
                    report($exception);
                }
            }
        }

        return $groups;
    }

    /** Deletes each dropped warm copy whose copy is gone: its place is free again. */
    private function forget(): void
    {
        ProofWarmCopy::query()->whereNotNull('dropped_at')
            ->whereNotIn('task_id', Instance::query()->select('task_workspace_owner_id')->whereNotNull('task_workspace_owner_id'))->delete();
    }

    private function current(ProofWarmCopy $warm, InstanceRuntime $source): bool
    {
        return $warm->seed_commit === $source->seed_commit && $source->seed_taken_at !== null && $warm->seed_taken_at->equalTo($source->seed_taken_at);
    }

    private function ready(ProofWarmCopy $warm): bool
    {
        $group = Task::topLevel()->with('taskable')->find($warm->task_id);

        return $group !== null && ProofCopies::copyState($group) === 'ready';
    }

    /**
     * The pool lock: the row of the proof default, which admitRead locks for each copy it starts. drain() and the create transaction of
     * keep() take it, so a drain never lands between the refill's check and its new copies.
     */
    private function lock(): void
    {
        Instance::query()->where('project_id', $this->settings->projectId())->where('name', DefaultInstanceName::Name)->lockForUpdate()->first();
    }

    private function group(): Task
    {
        return app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->settings->projectId(), 'Proof warm copy',
            'A warm proof copy that waits for a proof.', TaskGroupStatus::Todo, [new TaskInputData('Run the proof cases',
                'The Swamp workflow operation-proof-run-copy proves a command in this copy once a proof takes it.', [['id' => 'verdict', 'type' => 'review', 'description' => 'Every selected case passes.']])]));
    }

    private static function scope(): SettingScope
    {
        return new SettingScope(SettingScopeType::Gateway);
    }
}
