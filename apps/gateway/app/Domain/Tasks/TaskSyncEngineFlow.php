<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineEvidence;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Operations\OperationKinds;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\SourceControl\GitBranchName;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * `task:sync` of a task copy on the engine (docs/cli/task/engine/sync.mmd): the default branch into the copy's Node mirror
 * (`instance.fetch_branch`, which makes a missing mirror from the default's), then what the copy lacks of the pinned head as one
 * bundle, and only `refs/remotes/origin/<default>` moved (`instance.sync_base`), then the scratch cleared (`instance.clear_base`),
 * after a failure too. The read token is minted once per Operation and reaches only the Node, as a blob.
 */
final readonly class TaskSyncEngineFlow implements EngineDefinition, EngineEvidence
{
    public const string Kind = 'tasks.sync';

    /** The executor input that seeds a missing mirror from the default's, which an older executor refuses. */
    public const string SeedFeature = 'instance.fetch_seed';

    private const string Fetch = 'fetch';

    private const string Sync = 'sync';

    private const string Clear = 'clear';

    private const string ClearAfterFailure = 'clear_after_failure';

    private const string Commit = '/\A[0-9a-f]{40}([0-9a-f]{24})?\z/D';

    /** The credential's lifetime in the Gateway cache: the token's own hour. */
    private const int CredentialSeconds = 3600;

    public function __construct(private TaskSyncProjection $projection, private RepositoryReadAccess $reads, private MachineBus $bus) {}

    public function steps(Operation $operation): array
    {
        $intent = self::intent($operation);
        $node = ValidatedData::integer($operation->node_id);
        if (! in_array(self::SeedFeature, $this->bus->nodeFeatures($node), true)) {
            throw new EngineUnsupported('The Node\'s executor cannot make a missing mirror from the default\'s.');
        }
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
                'credential' => ['blob' => ['content' => $this->credential($operation)]], 'seed' => ValidatedData::string($copy['source_mirror'])],
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

    /** The output each step answers: the mirror head or null, the base evidence, or the cleanup. */
    public function evidence(string $step, string $output): bool
    {
        $value = json_decode($output, true);
        if (! is_array($value)) {
            return false;
        }
        $commit = static fn (mixed $value): bool => is_string($value) && preg_match(self::Commit, $value) === 1;
        $work = $value['work'] ?? null;

        return match ($step) {
            self::Fetch => array_keys($value) === ['commit'] && ($value['commit'] === null || $commit($value['commit'])),
            self::Sync => is_string($value['default_branch'] ?? null) && $commit($value['default_commit'] ?? null) && is_string($value['branch'] ?? null)
                && $commit($value['branch_commit'] ?? null) && is_int($value['behind_by'] ?? null) && $value['behind_by'] >= 0 && $commit($value['start'] ?? null)
                && is_array($work) && in_array($work['state'] ?? null, ['clean', 'dirty'], true),
            self::Clear, self::ClearAfterFailure => $value === ['cleaned' => true],
            default => false,
        };
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
     * The Project's read token as one credential-store line, minted once per Operation and sent again unchanged; empty for a public
     * repository read without one. Only the Gateway keeps it, encrypted, until the Operation ends.
     */
    private function credential(Operation $operation): string
    {
        $key = self::credentialKey($operation);
        $kept = Cache::get($key);
        if (is_string($kept)) {
            return Crypt::decryptString($kept);
        }
        $group = Task::topLevel()->with('project')->findOrFail($operation->target_id);
        $token = $this->reads->for($group->project->repositoryUrl(), $group->project->source_access)->githubToken();
        $line = $token === null ? '' : 'https://x-access-token:'.$token."@github.com\n";
        Cache::put($key, Crypt::encryptString($line), self::CredentialSeconds);

        return $line;
    }

    private static function credentialKey(Operation $operation): string
    {
        return 'tasks.sync.credential.'.$operation->id;
    }

    /**
     * The admitted intent of a task copy whose copy, runtime and inputs this flow covers, bound to the copy that runs for the group
     * on the Operation's Node. A group without a copy never gets here (`tasks.no_workspace` at admission); any other shape, and a
     * Project that reads with the Gateway's GitHub CLI login, runs wholly on Swamp.
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
        $name = static fn (mixed $value): bool => is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,62}\z/D', $value) === 1;
        $commit = static fn (mixed $value): bool => is_string($value) && preg_match(self::Commit, $value) === 1;
        if ($operation->kind !== self::Kind || $operation->target_type !== OperationKinds::TaskGroupTarget || ! is_array($copy) || ! is_array($workspace)
            || ! is_int($id) || $id < 1 || ($intent['node_id'] ?? null) !== $operation->node_id || ($intent['group_id'] ?? null) !== $operation->target_id
            || ! is_array($runtime) || array_keys($runtime) !== ['project', 'name'] || ! $name($runtime['project']) || ! $name($runtime['name'])
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
        $instance = Instance::query()->with('runtime', 'project')->find($id);
        $saved = $instance?->runtime;
        if ($instance === null || $saved === null || $instance->task_workspace_owner_id !== $operation->target_id || $instance->node_id !== $operation->node_id
            || $saved->incus_project !== $runtime['project'] || $saved->container_name !== $runtime['name']
            || $instance->project->source_access === ProjectSourceAccess::GhCli) {
            throw new EngineUnsupported('The sync runs on the engine only for the group\'s own copy with scoped repository access.');
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
