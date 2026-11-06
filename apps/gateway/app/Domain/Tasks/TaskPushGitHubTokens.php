<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\GitHub\RepositoryPullRequestAccess;
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
 * Mints the push token of a publish when its step runs, not when the Gateway admits it. A prep run reaches the publish step
 * after the VM, the author and the review rounds, which can take longer than the hour that an installation token lives.
 * The admitted intent holds only the vault key names; the Gateway writes the values here and returns the descriptors.
 * No task VM receives the token: the push and the pull request calls run on Node 209.
 */
final readonly class TaskPushGitHubTokens implements NativeGitHubTokenIssuer
{
    public function __construct(private DirectOperation $direct, private RepositoryPullRequestAccess $access, private SwampCommandStep $step) {}

    /** @return array<string,array{vault:string,key:string}> */
    public function issue(Operation $operation, int $callerNodeId, string $runId): array
    {
        $operation->refresh();
        $this->direct->assertRun($operation, $runId);
        if ($callerNodeId !== SwampNode::id() || $operation->target_type !== 'task_group' || $operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw $this->forbidden();
        }
        [$name, $references] = $this->admitted($operation);
        $group = Task::topLevel()->with('project')->find($operation->target_id);
        $repository = GitHubRepository::fromOrigin((string) $group?->project->repository_url);
        if ($repository === null || $repository->owner.'/'.$repository->name !== $name) {
            throw $this->forbidden();
        }
        try {
            $token = $this->access->token($repository);
        } catch (GitHubApiException) {
            throw new ResourceOperationException('tasks.publish_failed', 'The GitHub App cannot publish to this repository.', 503);
        }
        $values = ['github_token' => $token, 'git_authorization' => 'AUTHORIZATION: basic '.base64_encode('x-access-token:'.$token)];
        $copies = [];
        foreach ($values as $member => $value) {
            $reference = ValidatedData::object($references[$member] ?? null);
            $copies[$member] = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $value);
        }

        Activity::query()->create([
            'request_id' => $operation->request_id ?? (string) Str::uuid(),
            'command' => 'operation:github:mint', 'description' => 'Issued the scoped push token of a Task publication.',
            'subject_type' => Operation::class, 'subject_id' => $operation->id, 'status' => 'succeeded', 'exit_code' => 0,
            'properties' => ['operation_id' => $operation->id, 'run_id' => $runId, 'caller_node_id' => $callerNodeId,
                'repository' => $name, 'permissions' => [['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']]],
        ]);

        return $copies;
    }

    /**
     * The admitted repository and the vault descriptors of the token, by kind: a publish holds them in its own intent, and a prep run
     * holds them in the intent of its publish step.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function admitted(Operation $operation): array
    {
        $input = $operation->input;
        [$repository, $references] = match ($operation->kind) {
            'tasks.publish' => [$input['repository'] ?? null, $input['execution_secrets'] ?? null],
            'tasks.prep' => [ValidatedData::object($input['publish'] ?? [])['repository'] ?? null, $input['publish_secrets'] ?? null],
            default => throw $this->forbidden(),
        };
        if (! is_string($repository) || ! is_array($references)) {
            throw $this->forbidden();
        }

        return [$repository, ValidatedData::object($references)];
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.github_token_forbidden', 'This intent does not permit a push token.', 403);
    }
}
