<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Data\Tools\AdoptedToolData;
use App\Data\Tools\AdoptToolData;
use App\Data\Tools\ToolData;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Models\Node;

/** Records ownership of an installed package from the Node agent's latest report, at once and with no task (R1). */
final readonly class AdoptToolAction
{
    public function __construct(private StartToolOperationAction $tools, private NodeAgentLiveness $liveness) {}

    public function execute(AdoptToolData $data): AdoptedToolData
    {
        $node = Node::query()->find($data->nodeId);
        $agent = $node === null ? null : $this->liveness->for($node);
        [$tool, $outcome] = $this->tools->adopt($data->nodeId, $data->manager, $data->package, $data->versionConstraint, $data->repository, $agent);

        return new AdoptedToolData(ToolData::fromModel($tool->refresh(), $outcome), $agent);
    }
}
