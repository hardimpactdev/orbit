<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Record the native terminal result; executing steps never read or project Gateway state. */
final readonly class NodeNetworkProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []),
            array_flip(['network_proven', 'public_key', 'retained', 'error_code']));
        $wasTerminal = $operation->status->terminal();
        $projected = app(DirectOperation::class)->project($operation, $data, $result);
        if (! $wasTerminal && ($data['status'] ?? null) === 'succeeded') {
            app(VpnConfigurationRepository::class)->assignPeerKey(
                Node::query()->findOrFail($operation->target_id), ValidatedData::string($result['public_key']));
        }

        return $projected;
    }
}
