<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** The normal Caddy publish primitive, serialized with other whole-Node builds. */
final readonly class CaddyTransport implements OperationEffectTransport, OperationTransportInput
{
    public function __construct(private AgentCommandRunner $agents, private NodeCaddyBuildLock $lock, private NodeCaddyfileRenderer $renderer, private AgentEffectReceipts $receipts) {}

    public function schema(Operation $operation, array $run): array|bool
    {
        return AgentSchemas::inputSchema('caddy.publish');
    }

    public function wireInput(Operation $operation, array $run, #[SensitiveParameter] array $input): mixed
    {
        return AgentSchemas::wireInput($input);
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        return $this->lock->run($node->id, $node->name, function () use ($node, $input, $run): array {
            $render = $this->renderer->render(Node::query()->findOrFail($node->id));
            if (! $render->buildable() || $render->version !== $input['version']) {
                return ['error_code' => 'caddy.plan_stale'];
            }

            return $this->agents->run($node, 'caddy.publish', [...$input, 'effect_key' => $run['effect_key']]);
        });
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        return $this->receipts->read(Node::query()->findOrFail($operation->node_id), 'caddy.publish', $run['effect_key']);
    }
}
