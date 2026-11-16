<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

/** The `ci` members a Node update sets. A member left as null keeps its stored value. */
final readonly class CiPlacesInput
{
    public function __construct(
        public ?int $places = null,
        public ?bool $proofOnly = null,
    ) {}

    /** @return array{places?:int,proof_only?:bool} */
    public function toArray(): array
    {
        return [
            ...($this->places === null ? [] : ['places' => $this->places]),
            ...($this->proofOnly === null ? [] : ['proof_only' => $this->proofOnly]),
        ];
    }
}
