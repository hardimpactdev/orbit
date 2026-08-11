<?php

declare(strict_types=1);

namespace App\Services\Nodes;

final readonly class NodeCreationIngressPlacement
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public array $roles,
        public ?int $ingressNodeId,
        public ?string $ingressNodeName,
    ) {}
}
