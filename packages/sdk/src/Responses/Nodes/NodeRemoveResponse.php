<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Nodes;

final readonly class NodeRemoveResponse
{
    /**
     * @param  list<array<string, string>>  $warnings
     */
    public function __construct(
        public string $name,
        public bool $removed,
        public bool $removedSelf,
        public bool $wireguardPeerRemoved,
        public int $grantsRemoved,
        public array $warnings = [],
    ) {}
}
