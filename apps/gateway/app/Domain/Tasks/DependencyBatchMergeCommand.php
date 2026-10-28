<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

final readonly class DependencyBatchMergeCommand implements OperationGatewayCommand
{
    public function __construct(private DependencyBatchMergeGuard $guard, private GitHubApi $github) {}

    public function schema(): array
    {
        $properties = ['token' => ['type' => 'string', 'minLength' => 1], 'repository' => ['type' => 'string'], 'default_branch' => ['type' => 'string'], 'head_sha' => ['type' => 'string', 'pattern' => '^[0-9a-f]{40}$']];
        foreach (['group_id', 'project_id', 'publish_operation_id', 'pr_number'] as $name) {
            $properties[$name] = ['type' => 'integer', 'minimum' => 1];
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function run(Operation $operation, array $input): array
    {
        try {
            return $this->merge($operation, $input);
        } catch (ResourceOperationException $exception) {
            return ['error_code' => $exception->errorCode];
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function merge(Operation $operation, array $input): array
    {
        $group = Task::topLevel()->findOrFail($operation->target_id);
        $intent = $this->guard->lineage($group, ValidatedData::integer($input['publish_operation_id']), ValidatedData::string($input['head_sha']));
        if (Arr::sortRecursive($intent) !== Arr::sortRecursive($operation->input) || Arr::sortRecursive($input) !== Arr::sortRecursive([...$intent, 'token' => $input['token']])) {
            throw new ResourceOperationException('dependency_merge.lineage_changed', 'The admitted merge intent changed.', 409);
        }
        $pr = $this->guard->github($intent);
        if (($pr['merged'] ?? false) === true) {
            return ['merged' => true, 'head_sha' => $intent['head_sha'], 'merge_sha' => $pr['merge_commit_sha']];
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/'.$intent['repository']);
        assert($repository !== null);
        if (Arr::sortRecursive($this->guard->lineage($group, $intent['publish_operation_id'], $intent['head_sha'])) !== Arr::sortRecursive($intent)) {
            throw new ResourceOperationException('dependency_merge.lineage_changed', 'The batch changed before the merge effect.', 409);
        }
        $merged = $this->github->squashPullRequest(ValidatedData::string($input['token']), $repository, $intent['pr_number'], $intent['head_sha']);
        if (! $merged->merged || $merged->sha === null) {
            throw new ResourceOperationException('dependency_merge.merge_refused', 'GitHub refused the expected-head squash merge.', 409);
        }

        return ['merged' => true, 'head_sha' => $intent['head_sha'], 'merge_sha' => $merged->sha];
    }
}
