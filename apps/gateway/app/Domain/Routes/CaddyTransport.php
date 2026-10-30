<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationTransportInput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;
use SensitiveParameter;

/** The normal Caddy publish primitive, serialized with other whole-Node builds. */
final readonly class CaddyTransport implements OperationEffectTransport, OperationTransportInput, RoutePublicationPrimitive
{
    public function __construct(private AgentCommandRunner $agents, private NodeCaddyBuildLock $lock, private NodeCaddyfileRenderer $renderer) {}

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

        return $this->lock->run($node->id, $node->name, fn (): array => $this->publishChecked($node, $input, $run));
    }

    public function publish(Operation $operation, #[SensitiveParameter] array $input): array
    {
        return $this->publishChecked(Node::query()->findOrFail($operation->node_id), $input);
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>|null  $run
     * @return array<string, mixed>
     */
    private function publishChecked(Node $node, #[SensitiveParameter] array $input, ?array $run = null): array
    {
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
            return $this->agents->run($node, 'caddy.publish', $run === null ? $input : AgentEffectReceipts::input($run, $input));
        } catch (AgentCommandRefused $refused) {
            return $this->refusal($refused->errorCode, $refused->details['stage'] ?? null);
        }
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function receipt(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $raw = $this->agents->run(Node::query()->findOrFail($operation->node_id), 'agent.effect-receipt',
            ['command' => 'caddy.publish', 'effect_key' => $run['effect_key'], 'owner' => null]);
        $receipt = AgentEffectReceipts::decode($raw, $run['effect_key']);
        if ($receipt['state'] === 'failed' && is_string($receipt['result']['error_code'] ?? null)) {
            $receipt['result'] = $this->refusal($receipt['result']['error_code'], data_get($raw, 'result.error.details.stage'));
        }

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function refusal(string $code, mixed $stage): array
    {
        return ['error_code' => $code, ...($code === 'caddy.publication_failed' && $stage === 'validate' ? ['stage' => 'validate'] : [])];
    }
}
