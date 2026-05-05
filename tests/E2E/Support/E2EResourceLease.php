<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2EResourceLease
{
    private bool $released = false;

    public function __construct(
        private readonly string $backend,
        private readonly string $host,
        private readonly int $slot,
        private readonly string $path,
        private readonly string $owner,
    ) {}

    public function backend(): string
    {
        return $this->backend;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function slot(): int
    {
        return $this->slot;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if (! is_file($this->path)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($this->path), true);

        if (is_array($payload) && ($payload['owner'] ?? null) !== $this->owner) {
            return;
        }

        @unlink($this->path);
    }
}
