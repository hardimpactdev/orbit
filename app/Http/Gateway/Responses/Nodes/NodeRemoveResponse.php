<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeRemoveResponse
{
    public function __construct(
        public string $name,
        public bool $removed,
        public bool $removedSelf,
        public bool $wireguardPeerRemoved,
        public int $grantsRemoved,
    ) {}
}
