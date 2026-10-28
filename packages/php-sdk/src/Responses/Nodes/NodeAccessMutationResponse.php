<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Gateway access removal also revokes open Metrics streams through an Operation. */
final readonly class NodeAccessMutationResponse
{
    public function __construct(public RemovedNodeAccessResponse|OperationResponse $result) {}
}
