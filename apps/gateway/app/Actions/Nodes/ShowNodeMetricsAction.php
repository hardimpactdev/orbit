<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeMetricsData;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeFact;

final readonly class ShowNodeMetricsAction
{
    public function __construct(private NodeAgentLiveness $liveness) {}

    public function execute(Node $node): NodeMetricsData
    {
        $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Metrics)->first();

        if ($fact === null) {
            throw new ResourceOperationException(
                errorCode: 'node.metrics_unavailable',
                message: "Node [{$node->name}] has no recorded metrics snapshot yet.",
                status: 422,
            );
        }

        $agent = $this->liveness->for($node);

        return NodeMetricsData::fromRaw(
            $fact->data,
            $fact->observed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            $agent,
            $agent?->state !== NodeAgentState::Online,
        );
    }
}
