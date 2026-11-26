<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Engine\EngineAdmission;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineEvidence;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Executor\ExecutorJournalLookup;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * `task:sync` of a task copy on the engine (docs/cli/task/engine/sync.mmd): the default branch into the copy's Node mirror
 * (`instance.fetch_branch`, which makes a missing mirror from the default's), then what the copy lacks of the pinned head as one
 * bundle, and only `refs/remotes/origin/<default>` moved (`instance.sync_base`), then the scratch cleared (`instance.clear_base`),
 * after a failure too. The read token is minted at routing and reaches only the Node, as a blob; it is minted again only when the
 * Gateway lost it before any order carried it.
 */
final readonly class TaskSyncEngineFlow implements EngineAdmission, EngineDefinition, EngineEvidence
{
    public const string Kind = 'tasks.sync';

    /** The executor input that seeds a missing mirror from the default's, which an older executor refuses. */
    public const string SeedFeature = 'instance.fetch_seed';

    private const string Fetch = 'fetch';

    private const string Sync = 'sync';

    private const string Clear = 'clear';

    private const string ClearAfterFailure = 'clear_after_failure';

    private const string Commit = '/\A[0-9a-f]{40}([0-9a-f]{24})?\z/D';

    /** How long the Gateway keeps the admitted credential: the executor journal's replay window, and an hour more. */
    private const int CredentialSeconds = ExecutorJournalLookup::RetentionMs / 1000 + 3600;

    public function __construct(private TaskSyncProjection $projection, private RepositoryReadAccess $reads, private MachineBus $bus) {}

    /**
     * Decided once, when routing: the Node's executor takes the seed input, the intent names the group's own running copy on that
     * Node with its saved runtime, the Project's repository and the default's mirror, and the Project reads with scoped App access
     * or anonymously. Then the run's one read credential is minted and kept for every resend of the Operation.
     */
    public function admit(Operation $operation): void
    {
        $intent = self::intent($operation);
        $copy = ValidatedData::object($intent['copy']);
        $runtime = ValidatedData::object($copy['runtime']);
        $node = ValidatedData::integer($operation->node_id);
        if (! in_array(self::SeedFeature, $this->bus->nodeFeatures($node), true)) {
            throw new EngineUnsupported('The Node\'s executor cannot make a missing mirror from the default\'s.');
        }
        $instance = Instance::query()->with('runtime', 'project')->find(ValidatedData::integer($intent['instance_id']));
        $saved = $instance?->runtime;
        $source = $saved?->clone_source_instance_id === null ? null : Instance::query()->find($saved->clone_source_instance_id);
        $repository = $instance === null ? null : GitHubRepository::fromOrigin($instance->project->repositoryUrl());
        if ($instance === null || $saved === null || $instance->task_workspace_owner_id !== $operation->target_id || $instance->node_id !== $operation->node_id
            || $saved->incus_project !== $runtime['project'] || $saved->container_name !== $runtime['name']
            || $repository === null || ValidatedData::object($intent['workspace'])['repository'] !== 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git'
            || $copy['source_mirror'] !== ($source instanceof Instance ? "/var/lib/orbit/instance-repositories/{$source->id}.git" : '')
            || $instance->project->source_access === ProjectSourceAccess::GhCli) {
            throw new EngineUnsupported('The sync runs on the engine only for the group\'s own copy with scoped repository access.');
        }
        if (! Cache::has(self::credentialKey($operation))) {
            $this->mint($operation, $instance->project->source_access);
        }
    }

    /**
     * Mints the run's read credential with the access mode admission took, keeps it encrypted in the Gateway cache, and keeps its
     * digest and that mode with the Operation: the digest names the content the Node keeps once it read it, so a resend of a
     * published order can always rebuild it, also when the Gateway's cache lost the content.
     */
    private function mint(Operation $operation, ProjectSourceAccess $access): string
    {
        $token = $this->reads->for(ValidatedData::string(ValidatedData::object(ValidatedData::object($operation->input)['workspace'])['repository']), $access)->githubToken();
        $line = $token === null ? '' : 'https://x-access-token:'.$token."@github.com\n";
        Cache::put(self::credentialKey($operation), Crypt::encryptString($line), self::CredentialSeconds);
        app(OperationStore::class)->change($operation, ['state' => [...($operation->refresh()->state ?? []),
            'engine_credential' => ['sha256' => hash('sha256', $line), 'source_access' => $access->value]]]);

        return $line;
    }

    /**
     * Never two writers on one copy: an engine-eligible sync whose copy's removal was admitted first is refused, never sent to Swamp,
     * once routing admitted it and again in the bind's transaction (StartSwampRun). A removal admitted later finds the bound sync's
     * Instance lock (StartInstanceDestroyAction). Plans the engine does not cover never get here: they take the unchanged Swamp path.
     */
    /**
     * While the kind is an engine kind, a confirmed removal of the group's own copy refuses the sync before routing, so neither the
     * engine nor the Swamp fallback starts it beside the removal: the copy is `removing`, or a removal of it has not ended. An
     * intent that names no copy of the group, or an unknown one, confirms nothing and routes as before. With the kind off, nothing
     * changes (Swamp admission as it was).
     */
    public static function refuseConfirmedRemoval(Operation $operation): void
    {
        $enabled = config('orbit.engine.kinds');
        $id = ValidatedData::object($operation->input)['instance_id'] ?? null;
        if ($operation->kind !== self::Kind || ! is_array($enabled) || ! in_array(self::Kind, $enabled, true) || ! is_int($id)) {
            return;
        }
        $copy = Instance::query()->find($id);
        if ($copy === null || $copy->task_workspace_owner_id !== $operation->target_id) {
            return;
        }
        if ($copy->status === InstanceState::Removing || Operation::query()->whereIn('kind', InstanceDestroyKind::Removals)
            ->where('target_type', 'instance')->where('target_id', $copy->id)->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'The task copy is being removed.', 409);
        }
    }

    public static function assertCopyFree(Operation $operation): void
    {
        if ($operation->kind !== self::Kind) {
            return;
        }
        $instance = Instance::query()->find(ValidatedData::integer(self::intent($operation)['instance_id']));
        if ($instance === null || $instance->status === InstanceState::Removing) {
            throw new ResourceOperationException('operation.target_busy', 'The task copy is being removed.', 409);
        }
    }

    /** From the pinned intent alone: a bound run never asks the Node, the records or the Project again. */
    public function steps(Operation $operation): array
    {
        $intent = self::intent($operation);
        $node = ValidatedData::integer($operation->node_id);
        $instance = (string) ValidatedData::integer($intent['instance_id']);

        // Every step acts on the copy that admission verified and the Operation locks; the fetch target is the copy's mirror.
        return [
            new StepSpec(self::Fetch, $node, 'instance.fetch_branch', "{$node}:fetch-{$instance}", budgetMs: 200_000, resendMs: 60_000),
            new StepSpec(self::Sync, $node, 'instance.sync_base', $instance, [self::Fetch], budgetMs: 300_000, resendMs: 60_000),
            new StepSpec(self::Clear, $node, 'instance.clear_base', $instance, [self::Sync], budgetMs: 60_000, resendMs: 30_000),
            // What a failed or cancelled fetch or import left goes too, as the Swamp cleanup job runs after either.
            new StepSpec(self::ClearAfterFailure, $node, 'instance.clear_base', $instance, budgetMs: 60_000, resendMs: 30_000, onFailure: true),
        ];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $intent = self::intent($operation);
        $workspace = ValidatedData::object($intent['workspace']);
        $copy = ValidatedData::object($intent['copy']);
        $runtime = ValidatedData::object($copy['runtime']);
        $where = ['project' => ValidatedData::string($runtime['project']), 'name' => ValidatedData::string($runtime['name']),
            'instance_id' => ValidatedData::integer($intent['instance_id']), 'mirror' => ValidatedData::string($copy['mirror'])];

        return match ($step) {
            self::Fetch => ['mirror' => $where['mirror'], 'repository' => ValidatedData::string($workspace['repository']),
                'branch' => ValidatedData::string($workspace['default_branch']), 'protocol' => 'https',
                'credential' => ['blob' => $this->credential($operation)], 'seed' => ValidatedData::string($copy['source_mirror'])],
            // The head the fetch receipt returned, so a resend imports the same commit even when the origin moved. The publish history
            // rides in a blob, so no history outgrows the 4 KB order.
            self::Sync => [...$where, 'default_branch' => ValidatedData::string($workspace['default_branch']), 'branch' => ValidatedData::string($workspace['branch']),
                'head' => self::head($run), 'start' => ValidatedData::string($copy['start']),
                'published' => ['blob' => ['content' => json_encode(ValidatedData::stringList($copy['published'] ?? []), JSON_THROW_ON_ERROR)]],
                'approved' => ValidatedData::string($copy['approved'] ?? '')],
            self::Clear, self::ClearAfterFailure => $where,
            default => throw new LogicException("Unknown sync step [{$step}]."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('A sync has no local steps.');
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        Cache::forget(self::credentialKey($operation));
        $this->projection->applyEngine($operation, $run);
    }

    /**
     * The output each step answers: the mirror head or null, the cleanup, or the base evidence of this sync: the head the fetch
     * receipt pinned, the admitted branches and start, and the whole work read on that head (TaskWork).
     */
    public function evidence(Operation $operation, string $step, string $output): bool
    {
        $value = json_decode($output, true);
        if (! is_array($value)) {
            return false;
        }

        return match ($step) {
            self::Fetch => array_keys($value) === ['commit'] && ($value['commit'] === null || self::commit($value['commit'])),
            self::Sync => self::baseEvidence($operation, $value),
            self::Clear, self::ClearAfterFailure => $value === ['cleaned' => true],
            default => false,
        };
    }

    /** @param array<array-key, mixed> $value */
    private static function baseEvidence(Operation $operation, array $value): bool
    {
        $intent = self::intent($operation);
        $workspace = ValidatedData::object($intent['workspace']);
        $copy = ValidatedData::object($intent['copy']);
        $output = OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', self::Fetch)->value('output');
        $fetched = is_string($output) ? json_decode($output, true) : null;
        $head = is_array($fetched) ? ($fetched['commit'] ?? null) : null;
        $work = $value['work'] ?? null;
        $paths = is_array($work) ? ($work['paths'] ?? null) : null;
        $count = static fn (mixed $value): bool => is_int($value) && $value >= 0;
        $names = is_array($paths) ? ($paths['names'] ?? null) : null;

        return self::commit($head) && ($value['default_commit'] ?? null) === $head && ($value['default_branch'] ?? null) === $workspace['default_branch']
            && ($value['branch'] ?? null) === $workspace['branch'] && ($value['start'] ?? null) === $copy['start']
            && self::commit($value['branch_commit'] ?? null) && $count($value['behind_by'] ?? null) && is_bool($value['bundled'] ?? null) && is_bool($value['changed'] ?? null)
            && is_array($work) && in_array($work['state'] ?? null, ['clean', 'dirty'], true) && ($work['head'] ?? null) === $value['branch_commit']
            && ($work['default_commit'] ?? null) === $head && is_bool($work['empty'] ?? null)
            && is_array($paths) && $count($paths['count'] ?? null) && is_array($names) && array_is_list($names) && count($names) <= 5
            && count($names) <= $paths['count'] && array_all($names, static fn (mixed $name): bool => is_string($name))
            && $count($work['uncommitted'] ?? null) && $count($work['untracked'] ?? null) && $count($work['stashes'] ?? null)
            && array_key_exists('approved_held', $work) && (($copy['approved'] ?? '') === '' ? $work['approved_held'] === null : is_bool($work['approved_held']));
    }

    private static function commit(mixed $value): bool
    {
        return is_string($value) && preg_match(self::Commit, $value) === 1;
    }

    /** The Swamp step a failed engine step stands for, so a failure names the step the Swamp run would. */
    public static function label(string $step): string
    {
        return in_array($step, [self::Clear, self::ClearAfterFailure], true) ? 'Discard the base scratch' : 'Bring in the base';
    }

    /** The fetch receipt's mirror head, which its evidence check verified; null only when the origin lacks the default branch. */
    private static function head(EngineRun $run): ?string
    {
        $output = json_decode((string) $run->output(self::Fetch), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($output) || ! array_key_exists('commit', $output) || ($output['commit'] !== null && ! is_string($output['commit']))) {
            throw new LogicException('The fetch answered no mirror head.');
        }

        return $output['commit'];
    }

    /**
     * The read credential of this Operation, as one credential-store line; empty for a public repository read without one. Once a
     * `fetch` order was published, every send names that one credential: from the Gateway cache, or by the digest the Operation
     * kept, and none mints again. Before the first publication no Node holds it, so a credential the cache lost is minted once
     * more with the admitted access mode and uploaded with that first order.
     */
    /** @return array{content: string}|array{sha256: string} */
    private function credential(Operation $operation): array
    {
        $kept = Cache::get(self::credentialKey($operation));
        if (is_string($kept)) {
            return ['content' => Crypt::decryptString($kept)];
        }
        $admitted = ValidatedData::object($operation->state['engine_credential'] ?? null);
        $published = OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', self::Fetch)->whereNotNull('first_order_expires_ms')->exists();
        if (! $published) {
            // No order carried it yet, so no Node holds it: the run mints its credential again, with the mode admission took, and
            // uploads it with this first publication.
            $access = ProjectSourceAccess::tryFrom(ValidatedData::string($admitted['source_access'] ?? ''))
                ?? throw new LogicException('The admitted credential mode of this sync is gone.');

            return ['content' => $this->mint($operation, $access)];
        }
        $reference = $admitted['sha256'] ?? null;

        return is_string($reference) && preg_match('/\A[0-9a-f]{64}\z/D', $reference) === 1
            ? ['sha256' => $reference] : throw new LogicException('The admitted credential of this sync is gone.');
    }

    private static function credentialKey(Operation $operation): string
    {
        return 'tasks.sync.credential.'.$operation->id;
    }

    /**
     * The admitted intent of a task copy whose copy, runtime and inputs this flow covers, from the intent alone. A group without a
     * copy never gets here (`tasks.no_workspace` at admission); any other shape runs wholly on Swamp.
     *
     * @return array<string, mixed>
     */
    private static function intent(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input);
        $copy = $intent['copy'] ?? null;
        $workspace = $intent['workspace'] ?? null;
        $id = $intent['instance_id'] ?? null;
        $runtime = is_array($copy) ? ($copy['runtime'] ?? null) : null;
        // The executor resolves an Instance target only as `instance-<id>` in the Instance or nested-fleet Incus project (power
        // crate `incus`); any other saved runtime runs on Swamp, as an order would find no target.
        $commit = static fn (mixed $value): bool => is_string($value) && preg_match(self::Commit, $value) === 1;
        if ($operation->kind !== self::Kind || $operation->target_type !== OperationKinds::TaskGroupTarget || ! is_array($copy) || ! is_array($workspace)
            || ! is_int($id) || $id < 1 || ($intent['node_id'] ?? null) !== $operation->node_id || ($intent['group_id'] ?? null) !== $operation->target_id
            || ! is_array($runtime) || count($runtime) !== 2 || ! in_array($runtime['project'] ?? null, [InstanceHostIntent::Project, InstancePlacement::NestedProject], true)
            || ($runtime['name'] ?? null) !== 'instance-'.$id
            || ($copy['mirror'] ?? null) !== "/var/lib/orbit/instance-repositories/{$id}.git"
            || ! is_string($copy['source_mirror'] ?? null) || preg_match('#\A(/var/lib/orbit/instance-repositories/[1-9][0-9]*\.git)?\z#D', $copy['source_mirror']) !== 1
            || $copy['source_mirror'] === $copy['mirror'] || ! $commit($copy['start'] ?? null)
            || ! is_array($copy['published'] ?? []) || ! array_is_list($copy['published'] ?? []) || ! array_all($copy['published'] ?? [], $commit)
            || ! (($copy['approved'] ?? '') === '' || $commit($copy['approved']))
            || ! is_string($workspace['repository'] ?? null) || preg_match('#\Ahttps://github\.com/[^\s/]+/[^\s/]+\.git\z#D', $workspace['repository']) !== 1
            || ! is_string($workspace['default_branch'] ?? null) || ! GitBranchName::isValid($workspace['default_branch']) || ! self::executorBranch($workspace['default_branch'])
            || ($workspace['branch'] ?? null) !== 'task-'.$operation->target_id || ($copy['branch'] ?? null) !== $workspace['branch']) {
            throw new EngineUnsupported('Only a task copy\'s sync runs on the engine.');
        }

        return $intent;
    }

    /**
     * A branch name the executor accepts as well (instance crate `branch_name`): it cannot read as an option, leave `refs/heads`, or
     * end in a lock file name in any case.
     */
    private static function executorBranch(string $branch): bool
    {
        $last = basename($branch);
        $dot = strrpos($last, '.');

        return preg_match('/\A[^\-\/.]/', $branch) === 1 && preg_match('/[\/.]\z/', $branch) !== 1
            && ($dot === false || $dot === 0 || strtolower(substr($last, $dot + 1)) !== 'lock')
            && ! str_contains($branch, '..') && ! str_contains($branch, '//') && ! str_contains($branch, '@{')
            && preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $branch) !== 1;
    }
}
