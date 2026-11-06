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
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/**
 * Mints a task token when the step that needs it runs, never at admission. A prep run reaches its publish step 43 to 90 minutes
 * after admission, a provision can wait for capacity before it clones, and an installation token lives one hour. The admitted intent
 * holds only vault key names; the Gateway writes the values here and returns the descriptors.
 *
 * A task VM never receives a token. The clone, the push and the pull request calls run on Node 209, and the VM gets the clone as a bundle.
 *
 * - `clone` writes the Git authorization header of a read of the Project repository, or an empty value for a repository that needs none.
 * - `publish` writes the write token for the pull request and its Git authorization header. An approved push of a cancel takes the token only.
 * - `pull_request` writes the write token of one pull request step: ready, comment or merge.
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
        if ($callerNodeId !== SwampNode::id() || $operation->target_type !== 'task_group' || $operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw $this->forbidden();
        }
        $group = Task::topLevel()->with('project')->find($operation->target_id);
        if (! $group instanceof Task) {
            throw $this->forbidden();
        }

        return match ($purpose) {
            'clone' => $this->clone($operation, $group, $callerNodeId, $runId),
            'publish' => $this->publish($operation, $group, $callerNodeId, $runId),
            'pull_request' => $this->pullRequest($operation, $group, $callerNodeId, $runId),
            default => throw $this->forbidden(),
        };
    }

    /** @return array<string,array{vault:string,key:string}> */
    private function clone(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        // A provision holds its workspace in its own intent. A prep run, a proof run and an image refresh hold the intent of their provision child.
        $intent = match ($operation->kind) {
            'tasks.provision' => $operation->input,
            'tasks.prep', 'proof.command', 'task_vm.image_refresh' => ValidatedData::object($operation->input['provision'] ?? []),
            default => throw $this->forbidden(),
        };
        $repository = ValidatedData::object($intent['workspace'] ?? [])['repository'] ?? null;
        $reference = $intent['git_authorization'] ?? null;
        if (! is_string($repository) || $repository !== $group->project->repository_url || ($intent['project_id'] ?? null) !== $group->project_id || ! is_array($reference)) {
            throw $this->forbidden();
        }
        $git = $this->reads->for($repository, $group->project->source_access);
        $reference = ValidatedData::object($reference);
        $copy = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']),
            $git->isEmpty() ? '' : $git->variables['GIT_CONFIG_VALUE_0']);
        $this->record($operation, 'Issued the scoped clone credential of a Task workspace.', $callerNodeId, $runId, $repository, [['contents' => 'read']]);

        return ['git_authorization' => $copy];
    }

    /** @return array<string,array{vault:string,key:string}> */
    private function publish(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        // A publish holds the token names in its own intent; a prep run holds them in the intent of its publish step.
        // The approved push of a cancel holds the token name beside its remote and takes no Git authorization header.
        $push = ValidatedData::object($operation->input['push'] ?? []);
        $cancelled = GitHubRepository::fromOrigin(is_string($push['remote'] ?? null) ? $push['remote'] : '');
        [$name, $references, $members] = match ($operation->kind) {
            'tasks.publish' => [$operation->input['repository'] ?? null, $operation->input['execution_secrets'] ?? null, ['github_token', 'git_authorization']],
            'tasks.prep', 'tasks.prep_fix' => [ValidatedData::object($operation->input['publish'] ?? [])['repository'] ?? null, $operation->input['publish_secrets'] ?? null, ['github_token', 'git_authorization']],
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

    /** @return array<string,array{vault:string,key:string}> */
    private function pullRequest(Operation $operation, Task $group, int $callerNodeId, string $runId): array
    {
        $permissions = match ($operation->kind) {
            'tasks.pr_ready', 'tasks.pr_comment' => ['pull_requests' => 'write'],
            'tasks.pr_merge' => ['contents' => 'write', 'pull_requests' => 'write'],
            default => throw $this->forbidden(),
        };
        $name = $operation->input['repository'] ?? null;
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $reference = ValidatedData::object($operation->input['execution_secrets'] ?? [])['write_token'] ?? null;
        if (! is_string($name) || $repository === null || $repository->owner.'/'.$repository->name !== $name || ! is_array($reference)) {
            throw $this->forbidden();
        }
        $credentials = $this->store->credentials()
            ?? throw new ResourceOperationException('github.not_configured', 'Configure the GitHub App before merging a task pull request.', 409);
        try {
            $token = app(GitHubApi::class)->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $name, 'permissions' => $permissions]));
        } catch (GitHubApiException $exception) {
            report($exception);
            throw new ResourceOperationException('tasks_pr.github_unreadable', 'The Gateway could not mint a GitHub token for the step.', 502);
        }
        $reference = ValidatedData::object($reference);
        $copy = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $token->reveal());
        $this->record($operation, 'Issued the scoped token of a Task pull request step.', $callerNodeId, $runId, $name, [$permissions]);

        return ['write_token' => $copy];
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
