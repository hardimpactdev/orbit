<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationTransport;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;

/** The normal Caddy publish primitive, serialized with other whole-Node builds. */
final readonly class CaddyTransport implements OperationTransport
{
    public function __construct(private AgentCommandRunner $agents, private NodeCaddyBuildLock $lock, private NodeCaddyfileRenderer $renderer) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return AgentSchemas::inputSchema('caddy.publish');
    }

    public function run(Operation $operation, array $run, array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        return $this->lock->run($node->id, $node->name, function () use ($node, $input): array {
            $render = $this->renderer->render($node->fresh());
            if (! $render->buildable() || $render->version !== $input['version']) {
                return ['error_code' => 'caddy.plan_stale'];
            }

            return $this->agents->run($node, 'caddy.publish', $input);
        });
    }
}
