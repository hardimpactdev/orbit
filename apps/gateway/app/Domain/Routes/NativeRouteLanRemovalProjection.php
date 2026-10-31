<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Supplies captured public intent and records the shared terminal reader's result. */
final readonly class NativeRouteLanRemovalProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['lan_removal'] ?? []);
        if ($operation->kind !== 'route.lan_remove' || $operation->target_type !== 'route_effect'
            || ($intent['route_id'] ?? null) !== $operation->target_id || $operation->target_id !== ($operation->input['route_id'] ?? null)
            || $operation->node_id === null || ($intent['node_id'] ?? null) !== $operation->node_id
            || ! is_string($intent['destination'] ?? null) || filter_var($intent['destination'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException('route.lan_removal_invalid', 'The admitted Route LAN destination is missing.', 422);
        }

        return ['intent' => array_intersect_key($intent, array_flip(['route_id', 'node_id', 'destination'])), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip(['ready', 'error_code']));

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
