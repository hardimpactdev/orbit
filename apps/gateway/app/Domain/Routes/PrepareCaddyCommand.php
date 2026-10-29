<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use App\Models\Operation;

/** Records one whole-Node Caddy snapshot. This command does no Node work. */
final readonly class PrepareCaddyCommand implements OperationGatewayCommand
{
    public function __construct(private NodeCaddyfileRenderer $renderer, private NodeCaddyBuildLock $lock) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['reason'],
            'properties' => ['reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($operation->kind !== 'route.caddy_converge' || ! is_int($operation->node_id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A Node Caddy Operation is required.', 422);
        }
        $node = Node::query()->findOrFail($operation->node_id);

        return $this->lock->run($node->id, $node->name, function () use ($operation, $node): array {
            $operation->refresh();
            $stored = $operation->state['caddy_plan'] ?? null;
            if (is_array($stored)) {
                return ['prepared' => true, 'version' => $stored['version']];
            }
            $current = Node::query()->findOrFail($node->id);
            $caddyfile = $this->renderer->render($current);
            if (! $caddyfile->buildable()) {
                return ['error_code' => 'caddy.render_failed', 'stage' => 'render'];
            }
            $operation->update(['state' => [...($operation->state ?? []), 'caddy_plan' => CaddyPublicationPlan::input($caddyfile, $current->id)]]);

            return ['prepared' => true, 'version' => $caddyfile->version];
        });
    }
}
