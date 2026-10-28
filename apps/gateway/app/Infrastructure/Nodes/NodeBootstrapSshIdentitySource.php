<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;

/** Resolves the existing Gateway SSH identity inside the protected run boundary. */
final readonly class NodeBootstrapSshIdentitySource implements OperationSecretSource
{
    public function __construct(private SshKeyProvider $keys) {}

    public function name(): string
    {
        return 'node.bootstrap_ssh_identity';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['node.add', 'node.bootstrap_install'], true)
            && Node::query()->whereKey($operation->node_id)->exists() ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $parameter !== 'bootstrap_identity') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The SSH identity does not belong to this bootstrap Operation.', 422);
        }

        return new OperationSecretValue($this->keys->privateKeyPath());
    }
}
