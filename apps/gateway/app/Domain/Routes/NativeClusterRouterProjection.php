<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Store native transition checkpoints. The Gateway does not verify Swamp's DAG. */
final readonly class NativeClusterRouterProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        return $this->publicInput($operation, ValidatedData::integer($operation->node_id));
    }

    /** @return list<int> */
    public function participantNodeIds(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['cluster_router']);

        return array_values(array_unique(array_filter([
            $operation->node_id, is_array($intent['candidate']) ? ValidatedData::integer($intent['candidate']['node_id']) : null,
            ...$this->ids($intent['old_nodes'], 'node_id'),
            ...$this->ids($intent['workloads'], 'node_id'),
            ...ValidatedData::integerList($intent['edge_node_ids']), ...ValidatedData::integerList($intent['dns_node_ids']),
        ], is_int(...))));
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, $this->participantNodeIds($operation), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted Router participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $nodeId): array
    {
        $this->assertPublicParticipant($operation, $nodeId);
        $node = Node::query()->findOrFail($nodeId);
        $public = $operation->input;
        $public['caddy_publication'] = CaddyPublicationPlan::forNode($node);
        $public['php_publication'] = ['node_id' => $nodeId, 'agent_sha256' => app(AgentDelivery::class)->pin($node), ...app(SharedPhpPoolInput::class)->forNode($node)];
        $intent = ValidatedData::object($operation->input['cluster_router']);
        if (in_array($nodeId, ValidatedData::integerList($intent['dns_node_ids']), true)) {
            $dns = app(DevelopmentDnsConfigRenderer::class);
            $public['private_dns_publication'] = ['node_id' => $nodeId, 'records' => $dns->render(), 'catalog' => json_encode(['requesters' => $dns->registeredRequesters(), ...$dns->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"];
        }

        return $public;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = Arr::only($raw, ['checkpoint', 'ready', 'failed_step', 'error_code']);
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal) {
                $this->project($projected, [...$data, 'result' => $result]);
            }

            return $projected->refresh();
        });
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function project(Operation $operation, array $data): array
    {
        $result = ValidatedData::object($data['result'] ?? []);
        $intent = ValidatedData::object($operation->input['cluster_router']);
        $candidate = $intent['candidate'];
        $oldIds = $this->ids($intent['old_nodes'], 'assignment_id');
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        if ($status === OperationStatus::Running) {
            if (($result['checkpoint'] ?? null) === 'router-serving' && is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['failed_step' => ClusterRouterReplacementStep::RouterCaddy->value]);
            } elseif (($result['checkpoint'] ?? null) === 'router-selected' && is_array($candidate)) {
                NodeRole::query()->whereKey($oldIds)->update(['status' => LifecycleStatus::Removing, 'failed_step' => null]);
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['status' => LifecycleStatus::Active, 'failed_step' => ClusterRouterReplacementStep::DatabaseCutover->value, 'error_code' => null]);
            } elseif (($result['checkpoint'] ?? null) === 'old-router-withdrawn') {
                NodeRole::query()->whereKey($oldIds)->update(['status' => LifecycleStatus::Removing, 'failed_step' => ClusterRouterTransition::OldRouterCleanup]);
            } elseif (($result['checkpoint'] ?? null) === 'candidate-withdrawn' && is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'rollback:dns-publication', 'error_code' => $result['error_code'] ?? 'cluster.router_failed']);
            }
        } elseif ($status === OperationStatus::Succeeded) {
            NodeRole::query()->whereKey($oldIds)->delete();
            if (is_array($candidate)) {
                NodeRole::query()->whereKey($candidate['assignment_id'])->update(['failed_step' => null, 'error_code' => null]);
            }
        } elseif ($status === OperationStatus::Failed && is_array($candidate)) {
            $role = NodeRole::query()->find(ValidatedData::integer($candidate['assignment_id']));
            $role?->update(['status' => $role->status === LifecycleStatus::Active ? LifecycleStatus::Active : LifecycleStatus::Failed,
                'failed_step' => $result['failed_step'] ?? $role->failed_step ?? 'router-preparation', 'error_code' => $data['error_code'] ?? 'cluster.router_failed']);
        }

        return $result;
    }

    /** @return list<int> */
    private function ids(mixed $rows, string $field): array
    {
        if (! is_array($rows)) {
            throw new \UnexpectedValueException('Stored Router participants must be an array.');
        }

        return array_values(array_map(static fn (mixed $row): int => ValidatedData::integer(ValidatedData::object($row)[$field]), $rows));
    }
}
