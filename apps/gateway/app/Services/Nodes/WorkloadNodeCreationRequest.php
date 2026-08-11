<?php

declare(strict_types=1);

namespace App\Services\Nodes;

final readonly class WorkloadNodeCreationRequest
{
    /** @param list<string> $roles */
    public function __construct(
        public array $roles,
        public WorkloadNodeProvisioningInput $inputs,
        public ?int $ingressNodeId,
    ) {}
}
