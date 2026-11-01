<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/** A source-bound public recovery snapshot; no host calls or private output channel. */
final readonly class NodeAgentRekeyInput
{
    /** @return array<string,mixed> */
    public function capture(Node $node, int $sourceId): array
    {
        $source = Operation::query()->find($sourceId);
        $snapshot = ValidatedData::object($source?->input['_converge'] ?? []);
        $agent = ValidatedData::object(ValidatedData::object($snapshot['payloads'] ?? [])['agent'] ?? []);
        if (! app(ManagedNodeEligibility::class)->allows($node) || $source === null || $source->status->terminal()
            || $source->kind !== 'node.converge' || $source->target_type !== 'node'
            || $source->node_id !== $node->id || $source->target_id !== $node->id
            || ($snapshot['node_id'] ?? null) !== $node->id || ($agent['user'] ?? null) !== $node->user
            || ! is_string($snapshot['agent_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $snapshot['agent_sha256'])
            || ! is_string($source->state['swamp_run_id'] ?? null) || ! Str::isUuid($source->state['swamp_run_id'])) {
            throw new ResourceOperationException('node.agent_rekey_unavailable', 'Recovery requires this Node’s unfinished, bound native configure intent.', 409);
        }

        return ['node_id' => $node->id, 'configure_operation_id' => $sourceId,
            'agent_sha256' => $snapshot['agent_sha256'],
            'configuration' => array_intersect_key($agent, array_flip(['configuration', 'certificate', 'unit', 'checkouts', 'apps_root', 'user'])),
            'ssh' => app(SwampSshExecutionInput::class)->forNode($node)];
    }
}
