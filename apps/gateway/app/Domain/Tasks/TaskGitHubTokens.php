<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubAccessToken;
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
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mints a task token when the step that needs it runs, never at admission. A prep run reaches its publish step 43 to 90 minutes
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
 * - `publish` writes the write token for the pull request and its Git authorization header. An approved push of a cancel takes the token only.
 * - `pull_request` writes the write token of one pull request step: ready, merge, or the comment of a review round.
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

    /**
     * The vault keys of the dependency configuration of one task VM, named at admission by the provision and by a package install.
     *
     * @return array{composer:array{vault:string,key:string},npm:array{vault:string,key:string},token:array{vault:string,key:string}}
     */
    public static function dependencyAuth(TaskVm $vm): array
    {
        return ['composer' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-composer-'.$vm->id],
            'npm' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-npm-'.$vm->id],
            'token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-token-'.$vm->id]];
    }

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
            'clone' => $this->clone($operation, $group, $callerNodeId, $runId),
            'dependencies' => $this->dependencies($operation, $group, $callerNodeId, $runId),
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
     * A provision holds its workspace in its own intent. A prep run, a proof run and an image refresh hold the intent of their provision child.
     *
     * @return array<string,mixed>
     */
    private function provisionIntent(Operation $operation): array
    {
        return match ($operation->kind) {
            'tasks.provision', 'tasks.package' => $operation->input,
            'tasks.prep', 'proof.command' => ValidatedData::object($operation->input['provision'] ?? []),
            default => throw $this->forbidden(),
        };
    }

    /**
     * The Git authorization header of a read of the Project repository, written to the vault key an admission named.
     *
     * @param  array<string,mixed>  $reference
     * @return array{vault:string,key:string}
     */
    public function writeClone(Task $group, array $reference): array
    {
        $git = $this->reads->for($group->project->repository_url, $group->project->source_access);

        return $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $git->isEmpty() ? '' : $git->variables['GIT_CONFIG_VALUE_0']);
    }

    /** @return array<string,array{vault:string,key:string}> */
    private function clone(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        // A package install writes the dependency files and never clones.
        $intent = $operation->kind === 'tasks.package' ? throw $this->forbidden() : $this->provisionIntent($operation);
        $repository = ValidatedData::object($intent['workspace'] ?? [])['repository'] ?? null;
        $reference = $intent['git_authorization'] ?? null;
        if (! is_string($repository) || $repository !== $group->project->repository_url || ($intent['project_id'] ?? null) !== $group->project_id || ! is_array($reference)) {
            throw $this->forbidden();
        }
        $copy = $this->writeClone($group, ValidatedData::object($reference));
        $this->record($operation, 'Issued the scoped clone credential of a Task workspace.', $callerNodeId, $runId, $repository, [['contents' => 'read']]);

        return ['git_authorization' => $copy];
    }

    /**
     * The Composer and npm configuration of the setup steps: a read token that covers the private packages of the installation.
     * The token exists in the vault, then in two 0600 files of the setup account, and the workflow removes them.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    private function dependencies(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        $intent = $this->provisionIntent($operation);
        $references = ValidatedData::object($intent['dependency_auth'] ?? []);
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        if (($intent['project_id'] ?? null) !== $group->project_id || $repository === null
            || array_any(['composer', 'npm', 'token'], static fn (string $member): bool => ! is_array($references[$member] ?? null))) {
            throw $this->forbidden();
        }
        try {
            $token = $this->access->dependencyToken($repository);
        } catch (GitHubApiException) {
            // A repository the App does not cover: setup runs without private access.
            $token = null;
        }
        // `http-basic`, not `github-oauth`: Composer refuses an OAuth token with a character outside [A-Za-z0-9._], and the installation token
        // has more (a JWT after `ghs_`). GitHub takes `x-access-token` and the token as basic credentials.
        $values = ['composer' => $token === null ? '{}' : json_encode(['http-basic' => ['github.com' => ['username' => 'x-access-token', 'password' => $token]]], JSON_THROW_ON_ERROR),
            // No empty value: a vault put needs content, and a comment is a valid `.npmrc`.
            'npm' => $token === null ? "# no private access\n" : '//npm.pkg.github.com/:_authToken='.$token."\n",
            // The token alone, for the step that revokes it. The placeholder makes that revoke a harmless refusal.
            'token' => $token ?? 'no-token-minted'];
        $copies = [];
        try {
            foreach ($values as $member => $value) {
                $reference = ValidatedData::object($references[$member]);
                $copies[$member] = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $value);
            }
        } catch (\Throwable $exception) {
            // The workflow revokes the token after its mint step succeeds; a failed copy ends the mint step, so the token is revoked here.
            if ($token !== null && ! app(GitHubApi::class)->revokeInstallationToken(new GitHubAccessToken($token, gmdate(DATE_ATOM, time() + 3600)))) {
                Log::warning('operation.github_token_revoke_failed');
            }
            throw $exception;
        }
        if ($token !== null) {
            $this->record($operation, 'Issued the read token of the setup steps of a Task workspace.', $callerNodeId, $runId, $repository->owner.'/'.$repository->name, [['contents' => 'read']]);
        }

        return $copies;
    }

    /** @return array<string,array{vault:string,key:string}> */
    private function publish(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        // A publish holds the token names in its own intent; a prep run, a prep fix and a review round hold them in the intent of their publish step.
        // The approved push of a cancel holds the token name beside its remote and takes no Git authorization header.
        $push = ValidatedData::object($operation->input['push'] ?? []);
        $cancelled = GitHubRepository::fromOrigin(is_string($push['remote'] ?? null) ? $push['remote'] : '');
        [$name, $references, $members] = match ($operation->kind) {
            'tasks.publish' => [$operation->input['repository'] ?? null, $operation->input['execution_secrets'] ?? null, ['github_token', 'git_authorization']],
            'tasks.prep', 'tasks.prep_fix', 'tasks.review_round' => [ValidatedData::object($operation->input['publish'] ?? [])['repository'] ?? null, $operation->input['publish_secrets'] ?? null, ['github_token', 'git_authorization']],
            'tasks.cancel' => [$cancelled === null ? null : $cancelled->owner.'/'.$cancelled->name, isset($push['github_token']) ? ['github_token' => $push['github_token']] : null, ['github_token']],
            default => throw $this->forbidden(),
        };
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        if (! is_string($name) || ! is_array($references) || $repository === null || $repository->owner.'/'.$repository->name !== $name) {
            throw $this->forbidden();
        }
        try {
            $token = $this->access->token($repository);
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
        $this->record($operation, 'Issued the scoped push token of a Task publication.', $callerNodeId, $runId, $name,
            [['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']]);

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
            ['pull_request', 'tasks.pr_ready'], ['pull_request', 'tasks.review_round'] => ['write_token' => ['pull_requests' => 'write']],
            ['pull_request', 'tasks.pr_merge'], ['pull_request', 'project.pr_merge'] => ['write_token' => $write],
            // The read token comes first: a failure after it leaves the token with the least authority.
            ['dependency_merge', 'tasks.dependency_merge'] => ['read_token' => ['contents' => 'read', 'pull_requests' => 'read', 'checks' => 'read', 'actions' => 'read'],
                'write_token' => $write],
            default => throw $this->forbidden(),
        };
        $name = $operation->input['repository'] ?? null;
        $repository = GitHubRepository::fromOrigin($project->repository_url);
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
