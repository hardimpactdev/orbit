<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Nodes;

final readonly class NodeListResponse
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $nodes,
        public array $meta,
    ) {}
}
