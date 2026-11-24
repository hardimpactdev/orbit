<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/**
 * Mints a task token when the step that needs it runs, never at admission. A publish step can run long
 * after admission, a provision can wait for capacity before it clones, and an installation token lives one hour. The admitted intent
 * holds only vault key names; the Gateway writes the values here and returns the descriptors.
 *
 * A task VM receives no token. The clone, the push and the pull request calls run on Node 209, and the VM gets the clone as a bundle.
 * The one exception is the `dependencies` purpose: its token files live on the VM while the setup steps run.
 *
 * - `clone` writes the Git authorization header of a read of the Project repository, or an empty value for a repository that needs none.
 *   A sync run gets the same header without asking: the Gateway writes it as it starts the run ({@see self::writeClone()}).
 * - `dependencies` writes the Composer `auth.json` and the `.npmrc` of the setup steps: a read token of the installation, or an empty configuration,
 *   and the token alone for the step that revokes it.
 * - `publish` writes the write token for the pull request and its Git authorization header.
 * - `pull_request` writes the write token of one pull request step: ready or merge.
 * - `dependency_merge` writes the write and read tokens of a dependency batch squash.
 * - no purpose, for a `tasks.check` handoff, writes the write token, the Checks read token and the Git authorization header of its CI wait.
 */
final readonly class TaskGitHubTokens implements NativeGitHubTokenIssuer
{
    public function __construct(
        private DirectOperation $direct,
        private RepositoryPullRequestAccess $access,
        private RepositoryReadAccess $reads,
        private GitHubAppStore $store,
        private SwampCommandStep $step,
    ) {}

    /** @return array<string,array{vault:string,key:string}> */
    public function issue(Operation $operation, int $callerNodeId, string $runId, ?string $purpose = null): array
    {
        $operation->refresh();
        $this->direct->assertRun($operation, $runId);
        if ($purpose === null && $operation->kind === 'tasks.check') {
            return $this->check($operation, $callerNodeId, $runId);
        }
        if ($purpose === 'pull_request' && $operation->kind === 'project.pr_merge') {
            return $this->projectMerge($operation, $callerNodeId, $runId);
        }
        if ($callerNodeId !== SwampNode::id() || $operation->target_type !== 'task_group' || $operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw $this->forbidden();
        }
        $group = Task::topLevel()->with('project')->find($operation->target_id);
        if (! $group instanceof Task) {
            throw $this->forbidden();
        }

        return match ($purpose) {
            'publish' => $this->publish($operation, $group, $callerNodeId, $runId),
            'pull_request', 'dependency_merge' => $this->scoped($operation, $group->project, $callerNodeId, $runId, $purpose),
            default => throw $this->forbidden(),
        };
    }

    /**
     * The write token of the merge of a pull request that no Task group watches. The operation targets its Project.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    private function projectMerge(Operation $operation, int $callerNodeId, string $runId): array
    {
        $project = Project::query()->find($operation->target_id);
        if ($callerNodeId !== SwampNode::id() || $operation->target_type !== 'project' || ! $project instanceof Project || $operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw $this->forbidden();
        }

        return $this->scoped($operation, $project, $callerNodeId, $runId, 'pull_request');
    }

    /**
     * The handoff check of a Project with hosted CI refreshes its execution copies after the local checks. The operation's own Node
     * may ask as well as Node 209.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    private function check(Operation $operation, int $callerNodeId, string $runId): array
    {
        if ($operation->target_type !== 'task_group' || ! in_array($callerNodeId, [$operation->node_id, SwampNode::id()], true)
            || $operation->status->terminal() || $operation->cancel_requested_at !== null
            || ($operation->input['phase'] ?? null) !== 'handoff' || ! is_string($operation->input['check_name'] ?? null)
            || ! is_string($operation->input['repository'] ?? null)) {
            throw $this->forbidden();
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/'.$operation->input['repository']);
        if ($repository === null) {
            throw new ResourceOperationException('tasks.handoff_ci_unavailable', 'The admitted repository is unavailable.', 503);
        }
        $checks = $this->access->checksToken($repository);
        if ($checks === null) {
            throw new ResourceOperationException('tasks.handoff_ci_unavailable', 'The repository requires Checks read access.', 503);
        }
        $token = $this->access->token($repository);
        $values = ['github_token' => $token, 'checks_token' => $checks, 'git_authorization' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token)];
        $references = ValidatedData::object($operation->input['execution_secrets']);
        $copies = [];
        foreach ($values as $name => $value) {
            $reference = ValidatedData::object($references[$name]);
            $copies[$name] = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $value);
        }
        $this->record($operation, 'Issued scoped Task check repository credentials.', $callerNodeId, $runId, $operation->input['repository'],
            [['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write'], ['checks' => 'read']]);

        return $copies;
    }

    /**
     * The Git authorization header of a read of the Project repository, written to the vault key an admission named.
     *
     * @param  array<string,mixed>  $reference
     * @return array{vault:string,key:string}
     */
    public function writeClone(Task $group, array $reference): array
    {
        $git = $this->reads->for($group->project->repositoryUrl(), $group->project->source_access);

        return $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $git->isEmpty() ? '' : $git->variables['GIT_CONFIG_VALUE_0']);
    }

    /** @return array<string,array{vault:string,key:string}> */
    private function publish(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        // A publish holds the token names in its own intent.
        [$name, $references, $members] = match ($operation->kind) {
            'tasks.publish' => [$operation->input['repository'] ?? null, $operation->input['execution_secrets'] ?? null, ['github_token', 'git_authorization']],
            default => throw $this->forbidden(),
        };
        $repository = GitHubRepository::fromOrigin($group->project->repositoryUrl());
        if (! is_string($name) || ! is_array($references) || $repository === null || $repository->owner.'/'.$repository->name !== $name) {
            throw $this->forbidden();
        }
        // A publish runs from a task copy only, and its token cannot change workflows (docs/reference/tasks.md#the-copy-check).
        $permissions = ['contents' => 'write', 'pull_requests' => 'write'];
        try {
            $token = $this->access->copyPublishToken($repository);
        } catch (GitHubApiException) {
            throw new ResourceOperationException('tasks.publish_failed', 'The GitHub App cannot publish to this repository.', 503);
        }
        $references = ValidatedData::object($references);
        $values = array_intersect_key(['github_token' => $token, 'git_authorization' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token)], array_flip($members));
        $copies = [];
        foreach ($values as $member => $value) {
            $reference = ValidatedData::object($references[$member] ?? null);
            $copies[$member] = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $value);
        }
        $this->record($operation, 'Issued the scoped push token of a Task publication.', $callerNodeId, $runId, $name, [$permissions]);

        return $copies;
    }

    /**
     * The scoped tokens of one pull request step or of a dependency merge: one repository, the permissions of the kind, one vault key each.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    private function scoped(Operation $operation, Project $project, int $callerNodeId, string $runId, string $purpose): array
    {
        $write = ['contents' => 'write', 'pull_requests' => 'write'];
        $grants = match ([$purpose, $operation->kind]) {
            ['pull_request', 'tasks.pr_ready'] => ['write_token' => ['pull_requests' => 'write']],
            ['pull_request', 'tasks.pr_merge'], ['pull_request', 'project.pr_merge'] => ['write_token' => $write],
            // The read token comes first: a failure after it leaves the token with the least authority.
            ['dependency_merge', 'tasks.dependency_merge'] => ['read_token' => ['contents' => 'read', 'pull_requests' => 'read', 'checks' => 'read', 'actions' => 'read'],
                'write_token' => $write],
            default => throw $this->forbidden(),
        };
        $name = $operation->input['repository'] ?? null;
        $repository = GitHubRepository::fromOrigin($project->repositoryUrl());
        $references = ValidatedData::object($operation->input['execution_secrets'] ?? []);
        if (! is_string($name) || $repository === null || $repository->owner.'/'.$repository->name !== $name
            || array_any(array_keys($grants), static fn (string $member): bool => ! is_array($references[$member] ?? null))) {
            throw $this->forbidden();
        }
        $credentials = $this->store->credentials()
            ?? throw new ResourceOperationException('github.not_configured', 'Configure the GitHub App before merging a pull request.', 409);
        $copies = [];
        foreach ($grants as $member => $permissions) {
            try {
                $token = app(GitHubApi::class)->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $name, 'permissions' => $permissions]));
            } catch (GitHubApiException $exception) {
                report($exception);
                throw new ResourceOperationException('tasks_pr.github_unreadable', 'The Gateway could not mint a GitHub token for the step.', 502);
            }
            $reference = ValidatedData::object($references[$member]);
            $copies[$member] = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $token->reveal());
        }
        $this->record($operation, 'Issued the scoped tokens of a pull request step.', $callerNodeId, $runId, $name, array_values($grants));

        return $copies;
    }

    /** @param list<array<string,string>> $permissions */
    private function record(Operation $operation, string $description, int $callerNodeId, string $runId, string $repository, array $permissions): void
    {
        Activity::query()->create([
            'request_id' => $operation->request_id ?? (string) Str::uuid(),
            'command' => 'operation:github:mint', 'description' => $description,
            'subject_type' => Operation::class, 'subject_id' => $operation->id, 'status' => 'succeeded', 'exit_code' => 0,
            'properties' => ['operation_id' => $operation->id, 'run_id' => $runId, 'caller_node_id' => $callerNodeId, 'repository' => $repository, 'permissions' => $permissions],
        ]);
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.github_token_forbidden', 'This intent does not permit this token.', 403);
    }
}
