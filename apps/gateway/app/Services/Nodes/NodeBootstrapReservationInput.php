<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;

final readonly class NodeBootstrapReservationInput
{
    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $request
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        public string $name,
        public array $roles,
        public WorkloadNodeProvisioningInput $inputs,
        public Node $caller,
        public array $request,
        public NodeCreationInput $creationInput,
    ) {}
}
