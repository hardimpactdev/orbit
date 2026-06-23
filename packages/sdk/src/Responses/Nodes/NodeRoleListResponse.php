<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Nodes;

final readonly class NodeRoleListResponse
{
    /**
     * @param  list<array<string, mixed>>  $roles
     */
    public function __construct(
        public string $node,
        public array $roles,
    ) {}
}
