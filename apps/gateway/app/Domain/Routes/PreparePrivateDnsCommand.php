<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records DNS data from current fleet state. Does not execute a Node command. */
final readonly class PreparePrivateDnsCommand implements OperationGatewayCommand
{
    public function __construct(private DevelopmentDnsConfigRenderer $renderer) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['reason'],
            'properties' => ['reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if ($operation->kind !== 'route.private_dns_publish') {
            throw new ResourceOperationException('operation.execution_forbidden', 'A private DNS publication Operation is required.', 422);
        }
        $node = Node::query()->with('roles')->findOrFail($operation->node_id);
        if (! $node->roles->contains(static fn ($role): bool => in_array($role->role, [RoleName::Vpn, RoleName::Gateway], true))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Operation Node must serve private DNS.', 422);
        }
        $operation->refresh();
        $stored = $operation->state['private_dns_plan'] ?? null;
        if (! is_array($stored)) {
            $stored = ['records' => $this->renderer->render(), 'catalog' => json_encode([
                'requesters' => $this->renderer->registeredRequesters(),
                ...$this->renderer->catalog()->toPublished(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"];
            $operation->update(['state' => [...($operation->state ?? []), 'private_dns_plan' => $stored]]);
        }

        return ['prepared' => true, 'version' => hash('sha256', ValidatedData::string($stored['catalog']))];
    }
}
