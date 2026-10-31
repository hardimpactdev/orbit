<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Routes\RouteWithdrawalArtifacts;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record projection only. Every effect belongs to the bound native root. */
final readonly class InstanceDestroyProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct, private ProjectDirectInstanceDestroyAction $records, private RouteWithdrawalArtifacts $routes) {}

    public function input(Operation $operation): array
    {
        $input = app(InstanceDestroySourceInspection::class)->executable($operation->input);
        $detail = ValidatedData::object($input['direct_destroy']);
        $detail['routes'] = array_map(static function (mixed $value): array {
            $artifact = ValidatedData::object($value);
            unset($artifact['php']);

            return $artifact;
        }, InstanceLifecycleInput::members(['members' => $detail['routes']]));

        $input['direct_destroy'] = $detail;
        if ($operation->status->terminal()) {
            return $input;
        }
        // Render on every bound read. Native publishers call this only after their model lock.
        // This does not grant foreign participants access to the root endpoint.
        $publications = [];
        foreach (InstanceLifecycleInput::members($input) as $member) {
            $instance = Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($member['instance_id']));
            $artifact = $this->routes->forDevelopmentRemoval($instance, $instance->routes()->with('targets')->orderBy('routes.id')->get());
            unset($artifact['php']);
            $publications[] = $artifact;
        }

        return [...$input, 'direct_destroy' => $detail, 'withdrawal_publications' => $publications, 'caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id))];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        $detail = ValidatedData::object($operation->input['direct_destroy']);
        if (! in_array($callerNodeId, $operation->input['participant_node_ids'] ?? [], true)
            || ! in_array($callerNodeId, $detail['publication_node_ids'], true)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node is not an admitted publication participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);
        $current = $this->input($operation);
        $select = static function (array $rows) use ($callerNodeId): array {
            return array_map(static fn (array $row): array => ['nodes' => array_values(array_filter($row['nodes'], static fn (array $node): bool => $node['node_id'] === $callerNodeId)),
                'dns' => array_values(array_filter($row['dns'], static fn (array $node): bool => $node['node_id'] === $callerNodeId))], $rows);
        };

        return ['participant_node_ids' => $current['participant_node_ids'], 'publication_node_id' => $callerNodeId,
            'caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($callerNodeId)),
            'withdrawal_publications' => $select($current['withdrawal_publications'] ?? []),
            'direct_destroy' => ['routes' => $select(ValidatedData::object($operation->input['direct_destroy'])['routes'])]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = array_intersect_key($raw, array_flip(['removed', 'error_code', 'failed_step']));
            $terminal = $operation->status->terminal();
            $status = $data['status'];
            if (! $terminal && $status === 'running' && isset($raw['source_inspection'])) {
                app(InstanceDestroySourceInspection::class)->capture($operation, ValidatedData::object($raw['source_inspection']));
            }
            if (! $terminal && $status === 'succeeded') {
                $this->records->execute(app(InstanceDestroySourceInspection::class)->executable($operation->input), $result);
            }
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && in_array($status, ['failed', 'cancelled'], true)) {
                $step = match ($result['failed_step'] ?? null) {
                    'runtime', 'teardown', 'databases' => InstanceRemovalStep::RuntimeCleanup,
                    'php', 'caddy', 'dns', 'cleanup' => InstanceRemovalStep::RouteTargetClear,
                    'finalize_sources' => InstanceRemovalStep::SourceFinalization,
                    'complete' => InstanceRemovalStep::RowDeletion,
                    default => InstanceRemovalStep::SourcePreparation,
                };
                InstanceRemoval::query()->whereKey($operation->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
                    'error_code' => $data['error_code'] ?? 'operation.cancelled', 'current_step' => $step, 'failed_step' => $step]);
            }

            return $projected;
        });
    }
}
