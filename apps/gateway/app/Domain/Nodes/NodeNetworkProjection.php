<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public key recording precedes the native hub read. No Node effect or receipt validation. */
final readonly class NodeNetworkProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['network' => ValidatedData::object($operation->input['_network'] ?? []),
            'hub' => ValidatedData::object($operation->input['_wireguard'] ?? [])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $result = array_intersect_key($raw, array_flip(['network_proven', 'public_key', 'retained', 'restore_peer_key', 'error_code']));
        $wasTerminal = $operation->status->terminal();
        $projected = app(DirectOperation::class)->project($operation, $data, $result);
        if (! $wasTerminal && in_array($data['status'] ?? null, ['running', 'succeeded'], true)) {
            $node = Node::query()->findOrFail($operation->target_id);
            if (($result['restore_peer_key'] ?? false) === true) {
                $node->update(['wireguard_public_key' => ValidatedData::object($operation->input['_network'] ?? [])['existing_key'] ?? null]);
            } elseif (is_string($result['public_key'] ?? null)) {
                app(VpnConfigurationRepository::class)->assignPeerKey($node, $result['public_key']);
            }
        }

        return $projected;
    }
}
