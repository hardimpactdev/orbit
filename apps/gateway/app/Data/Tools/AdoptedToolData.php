<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Data\Nodes\NodeAgentData;

/** The adopted Tool and the liveness of the agent whose report it was checked against; null without an observing agent. */
final readonly class AdoptedToolData
{
    public function __construct(public ToolData $tool, public ?NodeAgentData $agent) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->tool->toArray(), 'agent' => $this->agent?->toArray()];
    }
}
