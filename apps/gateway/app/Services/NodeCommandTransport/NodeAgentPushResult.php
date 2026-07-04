<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeAgentPushResult
{
    /**
     * @param  list<array<array-key, mixed>>  $frames
     */
    public function __construct(
        public string $transport,
        public string $commandId,
        public string $status,
        public array $frames,
    ) {}
}
