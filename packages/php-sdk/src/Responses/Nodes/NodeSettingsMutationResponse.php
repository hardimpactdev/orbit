<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** A state-only patch completes immediately; an apps-root check returns an Operation. */
final readonly class NodeSettingsMutationResponse
{
    public function __construct(public NodeResponse|OperationResponse $result) {}
}
