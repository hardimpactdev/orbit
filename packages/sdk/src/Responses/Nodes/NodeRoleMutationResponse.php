<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Nodes;

final readonly class NodeRoleMutationResponse
{
    /**
     * @param  array<string, mixed>  $assignment
     */
    public function __construct(
        public string $node,
        public array $assignment = [],
        public ?string $removedRole = null,
        public ?bool $purgedData = null,
    ) {}
}
