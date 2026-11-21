<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

use SensitiveParameter;

/** A Process's recorded release artifact. */
final readonly class ProcessArtifactResponse
{
    public function __construct(
        public int $id,
        public string $name,
        public ProcessArtifactData $artifact,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            artifact: ProcessArtifactData::fromGatewayData(is_array($data['artifact'] ?? null) ? $data['artifact'] : []),
            requestId: $requestId,
        );
    }

    /** @return array{id: int, name: string, artifact: array<string, mixed>, request_id: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'artifact' => $this->artifact->toArray(), 'request_id' => $this->requestId];
    }
}
