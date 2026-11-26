<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Operations\OperationKinds;
use App\Domain\SourceControl\GitBranchName;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use LogicException;

/**
 * `task:sync` of a task copy on the engine (docs/cli/task/engine/sync.mmd): the default branch into the copy's Node mirror
 * (`instance.fetch_branch`), then what the copy lacks of the pinned head as one bundle, and only
 * `refs/remotes/origin/<default>` moved (`instance.sync_base`), then the scratch cleared (`instance.clear_base`), after a
 * failure too. The read token is minted when the fetch is sent and reaches only the Node, as a blob.
 */
final readonly class TaskSyncEngineFlow implements EngineDefinition
{
    public const string Kind = 'tasks.sync';

    private const string Fetch = 'fetch';

    private const string Sync = 'sync';

    private const string Clear = 'clear';

    private const string ClearAfterFailure = 'clear_after_failure';

    public function __construct(private TaskSyncProjection $projection, private RepositoryReadAccess $reads) {}

    public function steps(Operation $operation): array
    {
        $intent = self::intent($operation);
        $node = ValidatedData::integer($operation->node_id);
        $instance = (string) ValidatedData::integer($intent['instance_id']);

        return [
            new StepSpec(self::Fetch, $node, 'instance.fetch_branch', "{$node}:fetch-{$instance}", budgetMs: 200_000, resendMs: 60_000),
            new StepSpec(self::Sync, $node, 'instance.sync_base', $instance, [self::Fetch], budgetMs: 300_000, resendMs: 60_000),
            new StepSpec(self::Clear, $node, 'instance.clear_base', $instance, [self::Sync], budgetMs: 60_000, resendMs: 30_000),
            // What a failed fetch or import left goes too, as the Swamp cleanup job runs after either.
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
                'credential' => ['blob' => ['content' => $this->credential($operation)]]],
            // The head the fetch receipt returned, so a resend imports the same commit even when the origin moved.
            self::Sync => [...$where, 'default_branch' => ValidatedData::string($workspace['default_branch']), 'branch' => ValidatedData::string($workspace['branch']),
                'head' => self::head($run), 'start' => ValidatedData::string($copy['start']),
                'published' => ValidatedData::stringList($copy['published'] ?? []), 'approved' => ValidatedData::string($copy['approved'] ?? '')],
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
        $this->projection->applyEngine($operation, $run);
    }

    /** The Swamp step a failed engine step stands for, so a failure names the step the Swamp run would. */
    public static function label(string $step): string
    {
        return in_array($step, [self::Clear, self::ClearAfterFailure], true) ? 'Discard the base scratch' : 'Bring in the base';
    }

    /** The fetch receipt's mirror head; null when the origin lacks the default branch, which the import refuses. */
    private static function head(EngineRun $run): ?string
    {
        $output = json_decode((string) $run->output(self::Fetch), true);

        return is_array($output) && is_string($output['commit'] ?? null) ? $output['commit'] : null;
    }

    /** The Project's read token as one credential-store line, minted for this send; empty for a repository read without one. */
    private function credential(Operation $operation): string
    {
        $group = Task::topLevel()->with('project')->findOrFail($operation->target_id);
        $token = $this->reads->for($group->project->repositoryUrl(), $group->project->source_access)->githubToken();

        return $token === null ? '' : 'https://x-access-token:'.$token."@github.com\n";
    }

    /**
     * The admitted intent of a task copy. A group without a copy never gets here (`tasks.no_workspace` at admission); any other
     * shape runs on Swamp.
     *
     * @return array<string, mixed>
     */
    private static function intent(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input);
        $copy = $intent['copy'] ?? null;
        $workspace = $intent['workspace'] ?? null;
        $instance = $intent['instance_id'] ?? null;
        if ($operation->kind !== self::Kind || $operation->target_type !== OperationKinds::TaskGroupTarget || ! is_array($copy) || ! is_array($workspace)
            || ! is_int($instance) || $instance < 1 || ($intent['node_id'] ?? null) !== $operation->node_id || ($intent['group_id'] ?? null) !== $operation->target_id
            || ! is_array($copy['runtime'] ?? null) || ($copy['mirror'] ?? null) !== "/var/lib/orbit/instance-repositories/{$instance}.git"
            || ! is_string($copy['start'] ?? null) || preg_match('/\A[0-9a-f]{40}([0-9a-f]{24})?\z/D', $copy['start']) !== 1
            || ! is_string($workspace['repository'] ?? null) || preg_match('#\Ahttps://github\.com/[^\s/]+/[^\s/]+\.git\z#D', $workspace['repository']) !== 1
            || ! is_string($workspace['default_branch'] ?? null) || ! GitBranchName::isValid($workspace['default_branch'])
            || ($workspace['branch'] ?? null) !== 'task-'.$operation->target_id) {
            throw new EngineUnsupported('Only a task copy\'s sync runs on the engine.');
        }

        return $intent;
    }
}
