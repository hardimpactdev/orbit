<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Domain\AgentView\AgentStateView;

/** Reads only the Gateway's stored observer view; it never contacts a Node. */
final readonly class DoctorObservations
{
    public function __construct(private AgentStateView $view) {}

    /** @return array<string,mixed> */
    public function forNode(int $nodeId): array
    {
        $view = $this->view->node($nodeId);

        return ['node_id' => $nodeId, 'freshness' => $view->freshness->value,
            'received_at' => $view->receivedAt,
            'units' => $view->isFresh() ? (object) $view->units : (object) [],
            'docker' => $view->isFresh() ? $view->docker : null,
            'workspaces' => $view->isFresh() ? array_values($view->workspaces) : []];
    }
}
