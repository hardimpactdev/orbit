<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\AgentView\AgentStateView;
use App\Domain\Doctor\FactDoctor;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class NodeBaseInventoryProjection implements DirectOperationProjection, NativeOperationProjection
{
    // Observer reports refresh unchanged facts every ten minutes, with a one-minute sampling allowance.
    private const int FactSeconds = 660;

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $direct = app(DirectOperation::class);
            $direct->assertRun($current, ValidatedData::string($data['run_id']));
            if ($current->status->terminal()) {
                return $current;
            }
            $result = ValidatedData::object($data['result'] ?? []);
            if (($data['status'] ?? null) === 'succeeded') {
                if (($result['node_id'] ?? null) !== $current->node_id || ($result['profile'] ?? null) !== $current->input['profile']) {
                    throw new ResourceOperationException('operation.result_invalid', 'The receipt does not match the admitted Node or profile.', 422);
                }
                $node = Node::query()->findOrFail($current->node_id);
                $result['doctor'] = $this->health($node, CarbonImmutable::parse(ValidatedData::string($result['captured_at'])));
            } else {
                $result = array_intersect_key($result, ['error_code' => true]);
            }

            return $direct->project($current, $data, $result);
        });
    }

    /** @return array<string,mixed> */
    private function health(Node $node, CarbonImmutable $captured): array
    {
        $doctor = app(FactDoctor::class)->report([$node], []);
        $checkedAt = CarbonImmutable::now();
        $liveness = app(NodeAgentLiveness::class)->for($node);
        $seen = app(AgentStateView::class)->lastSeen($node->id);
        $fresh = $liveness?->state === NodeAgentState::Online && $seen !== null
            && abs($captured->getTimestamp() - $seen) <= AgentStateView::FreshSeconds;
        $facts = $this->factTimes($node, $captured);
        $contemporaneous = $fresh && abs($captured->diffInSeconds($checkedAt)) <= AgentStateView::FreshSeconds
            && $doctor->summary['unverifiable'] === 0
            && array_all($facts, static fn (array $fact): bool => $fact['fresh_for_capture']);

        return [
            'checked_at' => $checkedAt->toISOString(), 'healthy' => $doctor->healthy,
            'drift' => $doctor->summary['drift'], 'unverifiable' => $doctor->summary['unverifiable'],
            'agent_state' => $liveness?->state->value,
            'agent_seen_at' => $seen === null ? null : CarbonImmutable::createFromTimestamp($seen, 'UTC')->toISOString(),
            'agent_fresh_for_capture' => $fresh, 'doctor_fresh_for_capture' => $contemporaneous,
            'facts' => $facts,
        ];
    }

    /** @return array<string,array{observed_at:?string,confirmed_at:?string,fresh_for_capture:bool}> */
    private function factTimes(Node $node, CarbonImmutable $captured): array
    {
        $kinds = [NodeFactKind::Host, NodeFactKind::Units, NodeFactKind::Ufw];
        $records = NodeFact::query()->where('node_id', $node->id)->whereIn('kind', $kinds)->get()
            ->keyBy(static fn (NodeFact $fact): string => $fact->kind->value);
        $facts = [];
        foreach ($kinds as $kind) {
            $record = $records->get($kind->value);
            $at = $record?->observed_at;
            if ($kind === NodeFactKind::Host && $record !== null && is_array($record->data['filesystems'] ?? null)) {
                $root = array_find($record->data['filesystems'], static fn (mixed $row): bool => is_array($row) && ($row['mount'] ?? null) === '/');
                if (is_array($root) && is_string($root['observed_at'] ?? null)) {
                    // Doctor can use a kept root reading from before the latest host observation.
                    $at = CarbonImmutable::parse($root['observed_at']);
                }
            }
            $confirmed = $kind === NodeFactKind::Host ? $record?->root_confirmed_at : $record?->confirmed_at;
            $facts[$kind->value] = [
                'observed_at' => $at?->toISOString(),
                'confirmed_at' => $confirmed?->toISOString(),
                'fresh_for_capture' => $confirmed !== null && abs($captured->diffInSeconds($confirmed)) <= self::FactSeconds,
            ];
        }

        return $facts;
    }
}
