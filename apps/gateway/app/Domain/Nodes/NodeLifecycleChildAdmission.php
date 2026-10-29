<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Actions\Nodes\RemoveNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Throwable;

/** Owning admission for other families' recorded, already-reserved Node targets. */
final readonly class NodeLifecycleChildAdmission implements OperationChildAdmission
{
    public function __construct(private OperationIdempotency $idempotency) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        if ($this->idempotency->parent?->id !== $parent->id || $this->idempotency->childKey !== $intent['key']
            || ! is_int($intent['node_id'])) {
            throw $this->forbidden();
        }
        $node = Node::query()->lockForUpdate()->findOrFail($intent['node_id']);
        $input = $intent['input'];
        if ($intent['kind'] === 'node.add') {
            $fields = ['name', 'publicSshHost', 'roles', 'publicSshPort', 'user', 'orbitUser', 'wireguardIp',
                'wireguardEndpointOverride', 'dnsServerOverride', 'expectedSshHostFingerprint', 'platform', 'architecture',
                'tldProvided', 'tld', 'clusterProvided', 'clusterId', 'lanIpProvided', 'lanIp', 'settingsProvided', 'settings',
                'platformProvided', 'sshJumpNodeId', 'taskVmId'];
            if (array_diff(array_keys($input), $fields) !== [] || ($input['name'] ?? null) !== $node->name) {
                throw $this->forbidden();
            }
            try {
                $roles = $input['roles'] ?? [];
                if (! is_array($roles) || ! array_is_list($roles)) {
                    throw $this->forbidden();
                }
                $input['roles'] = array_map(static fn (mixed $role): RoleName => is_string($role) ? RoleName::from($role) : throw new \InvalidArgumentException, $roles);
                $data = ProvisionNodeData::from($input);
            } catch (Throwable) {
                throw $this->forbidden();
            }

            return app(ProvisionNodeAction::class)->start($data, $parent->request_id);
        }
        if ($intent['kind'] !== 'node.remove' || count($input) !== 4 || ! is_int($input['caller_node_id'] ?? null)
            || ! is_bool($input['offline'] ?? null) || ! is_bool($input['force'] ?? null) || ! is_bool($input['deleted'] ?? null)) {
            throw $this->forbidden();
        }
        $caller = Node::query()->findOrFail($input['caller_node_id']);

        return app(RemoveNodeAction::class)->start($node, $caller, $input['offline'], $input['force'], $input['deleted'], $parent->request_id);
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden', 'Node lifecycle work requires its recorded Node target and owning input.', 422);
    }
}
