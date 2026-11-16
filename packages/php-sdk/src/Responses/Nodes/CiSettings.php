<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

/** A CI Node's CI places, its `settings.ci`: runner jobs and proof copies share them, unless `proof_only` keeps them for proof copies. */
final readonly class CiSettings
{
    public function __construct(
        public int $places,
        public bool $proof_only = false,
    ) {}

    public static function fromGatewayData(mixed $data): ?self
    {
        if (! is_array($data) || ! is_int($data['places'] ?? null)) {
            return null;
        }

        return new self($data['places'], ($data['proof_only'] ?? false) === true);
    }

    /** @return array{places:int,proof_only:bool} */
    public function toArray(): array
    {
        return ['places' => $this->places, 'proof_only' => $this->proof_only];
    }
}
