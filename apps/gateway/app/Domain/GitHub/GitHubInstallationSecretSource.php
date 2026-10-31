<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use App\Actions\GitHub\IssueDirectGitHubTokenAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\Request;

/** Fixed kind-owned App authority, delivered only by the shared private reader. */
final readonly class GitHubInstallationSecretSource implements OperationSecretSource
{
    public function __construct(private DirectOperation $direct, private IssueDirectGitHubTokenAction $issue, private Request $request) {}

    public function name(): string
    {
        return 'github.installation';
    }

    public function targetTypes(): array
    {
        return ['node', 'instance', 'task_vm', 'recipe_pr', 'recipe_snapshot', 'recipe_artifacts'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return DirectOperation::owns($operation) && isset($this->direct->definition($operation->kind)['github_token'])
            ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $caller = ($this->request->getUserResolver())();
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== 'github'
            || ($this->direct->definition($operation->kind)['private_material']['github'] ?? null) !== $this->name()
            || ! $caller instanceof Node) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'The GitHub reference does not belong to this direct intent.', 403);
        }
        $issued = $this->issue->handle($operation, $caller->id, ValidatedData::string($this->request->query('run_id')));
        $grant = $issued['grant'];
        $credential = $issued['credential'];

        return new OperationSecretValue([
            'repository' => $grant->repository->owner.'/'.$grant->repository->name,
            'permissions' => $grant->permissions,
            'token' => $credential->reveal(),
            'expires_at' => $credential->expiresAt,
        ]);
    }
}
