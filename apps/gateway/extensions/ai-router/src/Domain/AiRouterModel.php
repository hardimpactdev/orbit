<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

final readonly class AiRouterModel
{
    public function __construct(
        public string $id,
        public string $provider,
    ) {}

    /**
     * @return array{id: string, provider: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
        ];
    }
}
