<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\NativeRouteRemovalPlan;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Capture both publication states before starting a run; workers never refresh intent over HTTP. */
final readonly class NodeRemovalIntent
{
    /** The caller owns the admission transaction and retains the original journal.
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(Node $target, Node $executor, array $input): array
    {
        $snapshot = ValidatedData::object($input['_node_removal']);
        $execution = ValidatedData::object($input['_removal']);
        $snapshot['route_removal'] = $execution['routes'];
        $operation = new Operation(['kind' => 'node.remove', 'target_type' => 'node',
            'target_id' => $target->id, 'node_id' => $executor->id, 'input' => $input]);
        $restore = $this->participants($snapshot, $operation, true);
        app(NodeRemovalRecords::class)->begin($snapshot);
        $routes = ValidatedData::object($execution['routes']);
        app(NativeRouteRemovalPlan::class)->recordWithdrawal(['routes' => array_map(
            static fn (mixed $row): array => ['route_id' => ValidatedData::integer(ValidatedData::object($row)['route_id'])],
            is_array($routes['routes']) ? array_values($routes['routes']) : [])]);

        return [...$execution, 'participant_node_ids' => $input['participant_node_ids'] ?? [],
            'retirement_host_key' => $execution['retirement'] === null ? null : $target->ssh_host_key,
            'participants' => $this->participants($snapshot, $operation, false),
            'restore_participants' => $restore];
    }

    /** @param array<string,mixed> $snapshot
     * @return list<array<string,mixed>>
     */
    private function participants(array $snapshot, Operation $operation, bool $restoring): array
    {
        $participants = [];
        foreach (is_array($snapshot['participants']) ? $snapshot['participants'] : [] as $row) {
            $id = ValidatedData::integer(ValidatedData::object($row)['node_id']);
            $participants[] = app(NodeRemovalPublicInput::class)->participant($snapshot,
                Node::query()->findOrFail($id), $restoring, $operation);
        }

        return $participants;
    }
}
