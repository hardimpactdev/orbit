<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe capture and bounded root result only; no host execution or child admission. */
final readonly class MetricsFleetProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['metrics_fleet'] ?? []);
        if ($operation->node_id !== ($plan['metrics_node_id'] ?? null) || $operation->target_id !== $operation->node_id || $operation->target_type !== 'metrics') {
            throw new ResourceOperationException('operation.execution_forbidden', 'Metrics requires its admitted root Node.', 422);
        }

        $member = array_find(MetricsFleetCapture::members($plan), static fn (array $node): bool => $node['node_id'] === $operation->node_id);
        $publication = ($member['caddy_managed'] ?? false) === true ? ['caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id))] : [];

        return [...$publication, 'participant_node_ids' => $operation->input['participant_node_ids'], 'metrics_fleet' => $plan];
    }

    public function assertPublicParticipant(Operation $operation, int $nodeId): void
    {
        $ids = ValidatedData::integerList($operation->input['participant_node_ids'] ?? []);
        $plan = ValidatedData::object($operation->input['metrics_fleet']);
        $member = array_find(MetricsFleetCapture::members($plan), static fn (array $node): bool => $node['node_id'] === $nodeId);
        if (! in_array($nodeId, $ids, true) || ($member['caddy_managed'] ?? false) !== true) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The public Metrics publication belongs to an admitted Caddy participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $nodeId): array
    {
        $this->assertPublicParticipant($operation, $nodeId);

        return ['caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($nodeId))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->direct->project($operation, $data, ValidatedData::object($data['result'] ?? []));
    }
}
