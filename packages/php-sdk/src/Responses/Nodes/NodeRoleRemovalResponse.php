<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** A missing Ingress assignment is already removed; other removals return their Operation. */
final readonly class NodeRoleRemovalResponse
{
    public function __construct(public NodeRoleMutationResponse|OperationResponse $result) {}
}
