<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/** Existing scoped mint primitive: refresh execution copies after local checks, never return values. */
final readonly class TaskCheckGitHubTokens
{
    public function __construct(private DirectOperation $direct, private RepositoryPullRequestAccess $access, private SwampCommandStep $step) {}

    /** @return array<string,array{vault:string,key:string}> */
    public function issue(Operation $operation, int $callerNodeId, string $runId): array
    {
        $operation->refresh();
        $this->direct->assertRun($operation, $runId);
        if ($operation->kind !== 'tasks.check' || $operation->target_type !== 'task_group'
            || ! in_array($callerNodeId, [$operation->node_id, 209], true)
            || $operation->status->terminal() || $operation->cancel_requested_at !== null
            || ($operation->input['phase'] ?? null) !== 'handoff' || ! is_string($operation->input['check_name'] ?? null)
            || ! is_string($operation->input['repository'] ?? null)) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'This intent does not permit repository credentials.', 403);
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/'.$operation->input['repository']);
        if ($repository === null) {
            throw new ResourceOperationException('tasks.handoff_ci_unavailable', 'The admitted repository is unavailable.', 503);
        }
        $checks = $this->access->checksToken($repository);
        if ($checks === null) {
            throw new ResourceOperationException('tasks.handoff_ci_unavailable', 'The repository requires Checks read access.', 503);
        }
        $values = ['github_token' => $this->access->token($repository), 'checks_token' => $checks];
        $references = ValidatedData::object($operation->input['execution_secrets']);
        $copies = [];
        foreach ($values as $name => $value) {
            $reference = ValidatedData::object($references[$name]);
            $copies[$name] = $this->step->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']), $value);
        }

        Activity::query()->create([
            'request_id' => $operation->request_id ?? (string) Str::uuid(),
            'command' => 'operation:github:mint', 'description' => 'Issued scoped Task check repository credentials.',
            'subject_type' => Operation::class, 'subject_id' => $operation->id, 'status' => 'succeeded', 'exit_code' => 0,
            'properties' => ['operation_id' => $operation->id, 'run_id' => $runId, 'caller_node_id' => $callerNodeId,
                'repository' => $operation->input['repository'], 'permissions' => [['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write'], ['checks' => 'read']]],
        ]);

        return $copies;
    }
}
