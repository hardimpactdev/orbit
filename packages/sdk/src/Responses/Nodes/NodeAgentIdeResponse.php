<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Nodes;

final readonly class NodeAgentIdeResponse
{
    /**
     * @param  array{adapter: string|null, source: string}  $agentIde
     */
    public function __construct(
        public string $name,
        public array $agentIde,
        public string $action,
    ) {}
}
