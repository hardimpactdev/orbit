<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\AgentIde;

final readonly class AgentIdeAdapterChoicesResponse
{
    /**
     * @param  list<string>  $reservedTokens
     * @param  list<array<string, mixed>>  $adapters
     */
    public function __construct(
        public string $scope,
        public array $reservedTokens,
        public array $adapters,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedInputs(): array
    {
        $inputs = [];

        foreach ($this->reservedTokens as $token) {
            if (is_string($token) && $token !== '') {
                $inputs[] = $token;
            }
        }

        foreach ($this->adapters as $adapter) {
            if (! is_array($adapter)) {
                continue;
            }

            $name = $adapter['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $inputs[] = $name;
            }
        }

        return $inputs;
    }
}
