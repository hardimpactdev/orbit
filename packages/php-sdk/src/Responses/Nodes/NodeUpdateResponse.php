<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** A record-only change returns the Node at once; an apps folder change returns the Operation that prepares it. */
final readonly class NodeUpdateResponse
{
    public function __construct(public NodeResponse|OperationResponse $result, public ?string $skipped = null) {}
}
