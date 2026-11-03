<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use Orbit\Sdk\Responses\Operations\OperationResponse;

/**
 * The answer to a registration: the Instance, registered at once from the Node agent's report (HTTP 201), or the
 * Operation of the native run that protects `.env` first (HTTP 202).
 */
final readonly class InstanceRegistrationResult
{
    public function __construct(
        public ?InstanceResponse $instance,
        public ?OperationResponse $operation,
    ) {}
}
