<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records only the identity proven by the pinned bootstrap installer. */
final readonly class NodeBootstrapOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        Node::query()->findOrFail($operation->node_id)->update([
            'architecture' => ValidatedData::string($outcome->result['architecture'] ?? null),
            'ssh_host_key_type' => ValidatedData::string($outcome->result['host_type'] ?? null),
            'ssh_host_key' => ValidatedData::string($outcome->result['public_key'] ?? null),
            'ssh_host_fingerprint' => ValidatedData::string($outcome->result['host_key_fingerprint'] ?? null),
        ]);
    }
}
