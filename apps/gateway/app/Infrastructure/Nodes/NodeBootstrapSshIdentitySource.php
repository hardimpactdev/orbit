<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeBootstrapIntent;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationConnectionSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;

/** Resolves the existing Gateway SSH identity inside the protected run boundary. */
final readonly class NodeBootstrapSshIdentitySource implements OperationConnectionSecretSource
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
        if ($operation->kind === 'node.remove') {
            return NodeRemovalInput::privateOwner($operation);
        }
        if (! app(NodeBootstrapIntent::class)->owns($operation)) {
            return null;
        }
        $owner = app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'bootstrap_identity');

        return $owner === $operation->target_id ? $owner : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $parameter !== 'bootstrap_identity') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The SSH identity does not belong to this bootstrap Operation.', 422);
        }

        return $this->resolveConnection($operation, $id);
    }

    public function resolveConnection(Operation $operation, int $id): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The SSH identity does not belong to this bootstrap Operation.', 422);
        }

        return new OperationSecretValue($this->keys->privateKeyPath());
    }
}
