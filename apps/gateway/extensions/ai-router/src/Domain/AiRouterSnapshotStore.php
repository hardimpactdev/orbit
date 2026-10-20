<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use Illuminate\Container\Attributes\Singleton;
use JsonException;

#[Singleton]
final readonly class AiRouterSnapshotStore
{
    public function __construct(
        private AiRouterCache $cache,
        private AiRouterPoolCompiler $compiler = new AiRouterPoolCompiler,
    ) {}

    public function snapshot(): ?AiRouterSnapshot
    {
        $encoded = $this->cache->get(AiRouterKeys::Snapshot);

        if (! is_string($encoded) || $encoded === '') {
            $accounts = $this->accounts();

            return $accounts === [] ? null : $this->compiler->compile($accounts, $this->collectedAt($accounts));
        }

        try {
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $this->hydrateSnapshot($decoded) : null;
    }

    /**
     * @return list<AiRouterAccount>
     */
    public function accounts(): array
    {
        $encoded = $this->cache->get(AiRouterKeys::Raw);

        if (! is_string($encoded) || $encoded === '') {
            return [];
        }

        try {
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $rows = is_array($decoded['accounts'] ?? null) ? $decoded['accounts'] : $decoded;
        $accounts = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $account = $this->hydrateAccount($row);

                if ($account instanceof AiRouterAccount) {
                    $accounts[] = $account;
                }
            }
        }

        return $accounts;
    }

    /**
     * @param  list<AiRouterAccount>  $accounts
     * @param  list<AiRouterModel>|null  $models  Null keeps the models already stored in the snapshot.
     */
    public function write(array $accounts, string $collectedAt, ?array $models = null): AiRouterSnapshot
    {
        if ($models === null) {
            $stored = $this->snapshot();
            $models = $stored->models ?? [];
        }

        $snapshot = $this->compiler->compile($accounts, $collectedAt, $models);
        $this->cache->put(AiRouterKeys::Raw, json_encode([
            'accounts' => array_map(static fn (AiRouterAccount $account): array => $account->toArray(), $accounts),
            'collected_at' => $collectedAt,
        ], JSON_THROW_ON_ERROR));
        $this->cache->put(AiRouterKeys::Snapshot, json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));

        return $snapshot;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function hydrateSnapshot(array $data): AiRouterSnapshot
    {
        $accounts = [];

        foreach (is_array($data['accounts'] ?? null) ? $data['accounts'] : [] as $row) {
            if (is_array($row)) {
                $account = $this->hydrateAccount($row);

                if ($account instanceof AiRouterAccount) {
                    $accounts[] = $account;
                }
            }
        }

        $collectedAt = is_string($data['collected_at'] ?? null) ? $data['collected_at'] : null;

        return $this->compiler->compile($accounts, $collectedAt, $this->hydrateModels($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<AiRouterModel>
     */
    private function hydrateModels(array $data): array
    {
        $models = [];

        foreach (is_array($data['models'] ?? null) ? $data['models'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '' || ! is_string($row['provider'] ?? null) || $row['provider'] === '') {
                continue;
            }

            $models[] = new AiRouterModel($row['id'], $row['provider']);
        }

        return $models;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function hydrateAccount(array $row): ?AiRouterAccount
    {
        if (! is_string($row['id'] ?? null) || ! is_string($row['provider'] ?? null) || ! is_string($row['label'] ?? null)) {
            return null;
        }

        $windows = [];

        foreach (is_array($row['windows'] ?? null) ? $row['windows'] : [] as $window) {
            if (! is_array($window) || ! is_string($window['label'] ?? null)) {
                continue;
            }

            $used = $window['used_percent'] ?? null;

            if (! is_int($used) && ! is_float($used)) {
                continue;
            }

            $windows[] = new AiRouterWindow(
                $window['label'],
                (float) $used,
                is_string($window['resets_at'] ?? null) ? $window['resets_at'] : null,
            );
        }

        return new AiRouterAccount(
            $row['id'],
            $row['provider'],
            $row['label'],
            $row['disabled'] === true,
            is_string($row['status'] ?? null) ? $row['status'] : null,
            $windows,
            is_string($row['error'] ?? null) ? $row['error'] : null,
        );
    }

    /**
     * @param  list<AiRouterAccount>  $accounts
     */
    private function collectedAt(array $accounts): ?string
    {
        return $accounts === [] ? null : date(DATE_ATOM);
    }
}
