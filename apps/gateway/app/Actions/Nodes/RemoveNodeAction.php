<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Models\Node;
use App\Models\Operation;

/** Records removal; Swamp owns every effect and ordered child. */
final readonly class RemoveNodeAction
{
    public function __construct(private AdmitNodeRemovalOperationAction $admission) {}

    public function start(Node $node, Node $caller, bool $offline = false, bool $force = false, bool $deleted = false, ?string $requestId = null): Operation
    {
        return $this->admission->execute($node, $caller, $offline, $force, $deleted, $requestId);
    }
}
