<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Fixed-repository App access, hydrated only for the Gateway's recipe PR command. */
final readonly class RecipeGithubSecretSource implements OperationSecretSource
{
    public function __construct(private RepositoryPullRequestAccess $access, private GatewayServingHost $gateway) {}

    public function name(): string
    {
        return 'github.app';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['recipe_pr', 'recipe_snapshot', 'recipe_artifacts'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $node = Node::query()->find($operation->node_id);

        return $operation->kind === 'fleet.recipe_pr' && $node !== null && $this->gateway->is($node) ? $node->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'token') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The App reference does not belong to this recipe Operation.', 422);
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit');
        if ($repository === null) {
            throw new \LogicException('The fixed recipe repository is invalid.');
        }

        return new OperationSecretValue($this->access->token($repository));
    }
}
