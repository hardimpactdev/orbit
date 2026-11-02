<?php

declare(strict_types=1);

namespace App\Domain\NodeFacts;

use App\Data\Nodes\NodeAgentData;
use App\Domain\AgentView\AgentStateView;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use Carbon\CarbonImmutable;

/**
 * Whether a Node's agent is online, from the presence heartbeat the agent view subscriber receives (every 5 seconds).
 * It never writes the database. Doctor and tool:adopt print it first, and while the agent is offline their findings
 * are possibly outdated.
 */
final readonly class NodeAgentLiveness
{
    public function __construct(private AgentStateView $view, private ManagedNodeEligibility $eligibility) {}

    /** Null for a Node that runs no observing agent. */
    public function for(Node $node): ?NodeAgentData
    {
        if (! $this->eligibility->allows($node)) {
            return null;
        }
        $subscriber = $this->view->subscriber();
        $now = CacheAgentStateView::now();
        if ($subscriber === null || $now - $subscriber->updatedAt > AgentStateView::SubscriberSeconds) {
            return new NodeAgentData(NodeAgentState::Unknown, null);
        }
        $received = $this->view->node($node->id)->receivedAt;
        if ($received !== null && $now - $received <= AgentStateView::FreshSeconds) {
            return new NodeAgentData(NodeAgentState::Online, null);
        }
        $seen = $this->view->lastSeen($node->id);

        return $seen === null
            ? new NodeAgentData(NodeAgentState::NotSeen, null)
            : new NodeAgentData(NodeAgentState::Offline, CarbonImmutable::createFromTimestamp($seen)->utc()->format('Y-m-d\TH:i:s\Z'));
    }
}
