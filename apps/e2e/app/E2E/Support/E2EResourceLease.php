<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

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

    public function path(): string
    {
        return $this->path;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * @return array{backend: string, host: string, slot: int, path: string, owner: string, retained: bool}
     */
    public function metadata(): array
    {
        return [
            'backend' => $this->backend,
            'host' => $this->host,
            'slot' => $this->slot,
            'path' => $this->path,
            'owner' => $this->owner,
            'retained' => $this->isRetained(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        $backend = $metadata['backend'] ?? null;
        $host = $metadata['host'] ?? null;
        $slot = $metadata['slot'] ?? null;
        $path = $metadata['path'] ?? null;
        $owner = $metadata['owner'] ?? null;

        if (
            ! is_string($backend)
            || ! is_string($host)
            || ! is_int($slot)
            || ! is_string($path)
            || ! is_string($owner)
        ) {
            throw new RuntimeException('Retained E2E lease metadata is malformed.');
        }

        return new self($backend, $host, $slot, $path, $owner);
    }

    public function retain(string $owner): self
    {
        $owner = trim($owner);

        if ($owner === '') {
            throw new RuntimeException('A retained E2E lease requires a non-empty owner.');
        }

        $payload = $this->readPayload();

        if ($payload === null) {
            throw new RuntimeException("Cannot retain missing E2E lease [{$this->path}].");
        }

        if (($payload['owner'] ?? null) !== $this->owner) {
            throw new RuntimeException("Cannot retain E2E lease [{$this->path}] owned by another process.");
        }

        $payload['owner'] = $owner;
        $payload['pid'] = null;
        $payload['retained'] = true;
        $payload['retained_at'] = time();

        $this->writePayload($payload);

        return new self($this->backend, $this->host, $this->slot, $this->path, $owner);
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

        $payload = $this->readPayload();

        if (is_array($payload) && ($payload['owner'] ?? null) !== $this->owner) {
            return;
        }

        @unlink($this->path);
    }

    private function isRetained(): bool
    {
        $payload = $this->readPayload();

        return is_array($payload) && ($payload['retained'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(): ?array
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $payload = json_decode($contents, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writePayload(array $payload): void
    {
        $written = file_put_contents($this->path, json_encode($payload, JSON_THROW_ON_ERROR));

        if ($written === false) {
            throw new RuntimeException("Failed to update E2E lease [{$this->path}].");
        }
    }
}
