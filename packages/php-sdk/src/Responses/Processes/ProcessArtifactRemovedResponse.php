<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

use SensitiveParameter;

/** The answer to removing a Process's artifact record; `removed` is false when there was none. */
final readonly class ProcessArtifactRemovedResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $removed,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            removed: ($data['removed'] ?? false) === true,
            requestId: $requestId,
        );
    }

    /** @return array{id: int, name: string, removed: bool, request_id: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'removed' => $this->removed, 'request_id' => $this->requestId];
    }
}
