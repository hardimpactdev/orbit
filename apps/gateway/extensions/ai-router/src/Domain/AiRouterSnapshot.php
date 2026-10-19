<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

final readonly class AiRouterSnapshot
{
    /**
     * @param  list<AiRouterAccount>  $accounts
     * @param  list<AiRouterProviderPool>  $providers
     * @param  list<AiRouterModel>  $models
     */
    public function __construct(
        public array $accounts,
        public array $providers,
        public ?string $collectedAt,
        public array $models = [],
    ) {}

    /**
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     providers: list<array<string, mixed>>,
     *     models: list<array{id: string, provider: string}>,
     *     collected_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'accounts' => array_map(static fn (AiRouterAccount $account): array => $account->toArray(), $this->accounts),
            'providers' => array_map(static fn (AiRouterProviderPool $pool): array => $pool->toArray(), $this->providers),
            'models' => array_map(static fn (AiRouterModel $model): array => $model->toArray(), $this->models),
            'collected_at' => $this->collectedAt,
        ];
    }

    public function provider(string $provider): ?AiRouterProviderPool
    {
        foreach ($this->providers as $pool) {
            if ($pool->provider === $provider) {
                return $pool;
            }
        }

        return null;
    }
}
