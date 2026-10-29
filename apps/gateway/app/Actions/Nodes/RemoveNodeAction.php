<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\RemoveNodeData;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Gateway callers await the same Swamp Operation returned by HTTP admission. */
final readonly class RemoveNodeAction
{
    public function __construct(private AdmitNodeRemovalOperationAction $admission, private AwaitOperation $await) {}

    public function start(Node $node, Node $caller, bool $offline = false, bool $force = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        return $this->admission->execute($node, $caller, $offline, $force, $deleted, $requestId);
    }

    public function execute(Node $node, Node $caller, bool $offline = false, bool $force = false, bool $deleted = false): RemoveNodeData
    {
        $operation = $this->await->execute($this->start($node, $caller, $offline, $force, $deleted));
        $result = $operation->result ?? [];
        if ($operation->status !== OperationStatus::Succeeded || ($result['removed'] ?? null) !== true) {
            throw new ResourceOperationException($operation->error_code ?? 'node.removal_failed',
                "Node removal did not succeed; follow Operation [{$operation->id}].", 409);
        }

        return new RemoveNodeData(id: ValidatedData::integer($result['id']), name: ValidatedData::string($result['name']), removed: true,
            wireguardPeerRemoved: ($result['wireguard_peer_removed'] ?? null) === true, dnsRecordsRemoved: ($result['dns_records_removed'] ?? null) === true,
            degradation: ValidatedData::nullableString($result['degradation'] ?? null), rolesShed: ValidatedData::stringList($result['roles_shed'] ?? []),
            retainedOnNode: ValidatedData::stringList($result['retained_on_node'] ?? []), followUp: ValidatedData::nullableString($result['follow_up'] ?? null));
    }
}
