<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Records each Node's outcome of a `fleet.reboot` pass (ADR 0253) on the Operation, in pass order. Task VMs that the drain stopped are recorded `parked`, so the next task call wakes them. A failed
 * or unknown Node raises `reboot_halted`; a Node due for more than three days raises `reboot_overdue`.
 */
final readonly class FleetRebootProjection implements DirectOperationProjection
{
    public const array Outcomes = ['rebooted', 'scheduled', 'not_due', 'deferred', 'skipped', 'failed', 'unknown'];

    /** The record fields a Node's result keeps. */
    private const array Fields = ['node_id', 'name', 'outcome', 'reason', 'step', 'error_code', 'error_detail', 'kernel_before', 'kernel_after', 'due_since',
        'boot_id_before', 'parked', 'failed_units', 'drain_seconds', 'reboot_seconds', 'rebooted_at', 'ci_stopped', 'reran', 'rerun_refused',
        'rerun_pending', 'rerun_truncated', 'rerun_error'];

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'], 'execution_secrets' => $operation->input['execution_secrets'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $raw = ValidatedData::object($data['result'] ?? []);
        $reported = is_array($raw['nodes'] ?? null) ? array_map(ValidatedData::object(...), array_values($raw['nodes'])) : [];
        $valid = Validator::make(['nodes' => $reported], [
            'nodes.*.node_id' => ['required', 'integer', 'distinct'],
            'nodes.*.outcome' => ['required', 'in:'.implode(',', self::Outcomes)],
            'nodes.*.error_code' => ['nullable', 'string', 'max:100'],
            'nodes.*.error_detail' => ['nullable', 'string', 'max:4096'],
        ]);
        if ($valid->fails()) {
            throw new ResourceOperationException('operation.result_invalid', 'Each visited Node needs one known outcome.', 422);
        }
        $byNode = [];
        foreach ($reported as $node) {
            $byNode[ValidatedData::integer($node['node_id'])] = array_intersect_key($node, array_flip(self::Fields));
        }
        $intent = ValidatedData::object($operation->input['intent'] ?? []);
        // JSON object keys that are digits decode as integer keys, so read the admitted Node by its integer ID.
        $admitted = is_array($intent['nodes'] ?? null) ? $intent['nodes'] : [];
        $nodes = [];
        $halted = false;
        foreach (ValidatedData::integerList($intent['order'] ?? []) as $id) {
            // A run-folder record cannot prove a visit unless the root delivered it. Only a recorded halt proves later Nodes were not reached.
            $node = $byNode[$id] ?? ['node_id' => $id, 'outcome' => $halted ? 'skipped' : 'unknown',
                'reason' => $halted ? 'not_reached' : 'run_interrupted'];
            $nodes[] = [...$node, 'reboot_hold' => is_array($admitted[$id] ?? null) && ($admitted[$id]['defer'] ?? null) === 'operator_hold'];
            $halted = $halted || ($node['outcome'] ?? null) === 'failed'
                || (($node['outcome'] ?? null) === 'skipped' && ($node['reason'] ?? null) === 'halted');
        }
        $unknown = array_find($nodes, static fn (array $node): bool => ($node['outcome'] ?? null) === 'unknown');
        $failed = array_find($nodes, static fn (array $node): bool => ($node['outcome'] ?? null) === 'failed') ?? $unknown;
        if ($failed !== null) {
            $failed['error_code'] = is_string($failed['error_code'] ?? null) ? $failed['error_code']
                : ($failed['outcome'] === 'unknown' ? 'fleet.reboot_interrupted' : 'fleet.reboot_failed');
            if ($unknown !== null && in_array($data['status'] ?? null, ['succeeded', 'failed', 'interrupted', 'cancelled'], true)) {
                $data = [...$data, 'status' => 'failed', 'error_code' => $failed['error_code']];
            }
        }
        $result = ['nodes' => $nodes, ...($failed !== null ? ['error_code' => $failed['error_code']] : [])];

        return DB::transaction(function () use ($operation, $data, $result, $nodes, $failed): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal || ! $projected->status->terminal()) {
                return $projected;
            }
            foreach ($nodes as $node) {
                $parked = is_array($node['parked'] ?? null) ? array_values(array_filter($node['parked'], is_string(...))) : [];
                if ($parked !== []) {
                    TaskVm::query()->where('host_node_id', ValidatedData::integer($node['node_id']))->whereIn('name', $parked)->where('state', TaskVmState::Ready)
                        ->update(['state' => TaskVmState::Parked]);
                }
            }
            DB::afterCommit(static function () use ($projected, $nodes, $failed): void {
                $alerts = app(FleetRebootAlerts::class);
                if ($failed !== null) {
                    $alerts->halted($projected, $failed);
                }
                foreach ($nodes as $node) {
                    $alerts->overdue($node, FleetReboot::dueSince(ValidatedData::integer($node['node_id'])));
                }
            });

            return $projected;
        });
    }
}
