<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

final readonly class AiRouterProviderPool
{
    /**
     * @param  list<AiRouterWindow>  $windows
     * @param  list<AiRouterAccount>  $accounts
     */
    public function __construct(
        public string $provider,
        public array $windows,
        public array $accounts,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     windows: list<array{label: string, used_percent: float, remaining_percent: float, resets_at: string|null}>,
     *     accounts: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'windows' => array_map(static fn (AiRouterWindow $window): array => $window->toArray(), $this->windows),
            'accounts' => array_map(static fn (AiRouterAccount $account): array => $account->toArray(), $this->accounts),
        ];
    }
}
