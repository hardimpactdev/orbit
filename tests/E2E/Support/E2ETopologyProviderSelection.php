<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyProviderSelection
{
    public function __construct(
        public ?E2ETopologyProvider $provider,
        public string $message,
    ) {}

    public function available(): bool
    {
        return $this->provider !== null;
    }

    public function provider(): E2ETopologyProvider
    {
        return $this->provider ?? throw new \RuntimeException($this->message);
    }
}
