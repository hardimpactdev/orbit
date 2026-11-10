<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Node;

/**
 * What the daily `fleet.agent_tools` run keeps current ([Extensions](/reference/extensions#plug-in-points), ADR 0255). Conn names
 * the Nodes whose host T3 server runs the agent CLIs; the AI router names its router. Each contribution decides for itself
 * whether its extension is on: a disabled extension contributes nothing.
 */
interface AgentToolsContribution
{
    /** @return list<Node> The Nodes whose host runs a T3 server, and so the agent CLIs it starts. */
    public function hosts(): array;

    /**
     * The model router to keep at the latest upstream release, or null.
     *
     * @return array<string, mixed>|null
     */
    public function router(): ?array;
}
