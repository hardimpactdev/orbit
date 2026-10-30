<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** One convergent Caddy primitive. Swamp owns serialization and retry. */
final readonly class CaddyTransport implements OperationTransport, OperationTransportInput, RoutePublicationPrimitive
{
    public function __construct(private AgentCommandRunner $agents, private NodeCaddyfileRenderer $renderer) {}

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
        return $this->publish($operation, $input);
    }

    public function publish(Operation $operation, #[SensitiveParameter] array $input): array
    {
        $node = Node::query()->findOrFail($operation->node_id);

        $render = $this->renderer->render(Node::query()->findOrFail($node->id));
        if (! $render->buildable()) {
            return ['error_code' => 'caddy.plan_stale'];
        }

        foreach (CaddyPublicationPlan::input($render, $node->id) as $field => $value) {
            if (! array_key_exists($field, $input) || $input[$field] !== $value) {
                return ['error_code' => 'caddy.plan_stale'];
            }
        }

        try {
            return $this->agents->run($node, 'caddy.publish', $input);
        } catch (AgentCommandRefused $refused) {
            return $this->refusal($refused->errorCode, $refused->details['stage'] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function refusal(string $code, mixed $stage): array
    {
        return ['error_code' => $code, ...($code === 'caddy.publication_failed' && $stage === 'validate' ? ['stage' => 'validate'] : [])];
    }
}
