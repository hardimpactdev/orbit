<?php

declare(strict_types=1);

namespace App\Data\GitHub;

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;

/** Public identity and a native observation; unknown is never observed absence. */
final readonly class GitHubAppData
{
    /** @param list<array<string,mixed>> $installations */
    public function __construct(public ?GitHubAppCredentials $app, public array $installations, public string $installationsStatus = 'unknown', public ?string $observedAt = null, public string $status = 'registered') {}

    public static function fromStore(GitHubAppStore $store): self
    {
        $observation = $store->observation();
        $observedAt = $observation['observed_at'] ?? null;
        $observedAt = is_string($observedAt) ? $observedAt : null;
        $status = $observedAt === null ? 'unknown' : (CarbonImmutable::parse($observedAt)->gt(now()->subMinutes(5)) ? 'current' : 'stale');

        $app = $store->credentials();
        $exchangeId = $store->exchange()['operation_id'] ?? null;
        $exchangeOperation = is_int($exchangeId) ? Operation::query()->find($exchangeId) : null;
        $registrationStatus = $app !== null ? 'registered' : ($exchangeOperation?->status->terminal() ? $exchangeOperation->status->value : 'pending');

        return new self($app, is_array($observation['installations'] ?? null) ? array_values(array_map(ValidatedData::object(...), $observation['installations'])) : [], $status, $observedAt, $registrationStatus);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'name' => $this->app?->name, 'slug' => $this->app?->slug, 'app_id' => $this->app?->appId,
            'owner' => $this->app?->owner, 'url' => $this->app?->url, 'settings_url' => $this->app?->settingsUrl(),
            'installations' => $this->installations, 'installations_status' => $this->installationsStatus, 'observed_at' => $this->observedAt,
        ];
    }
}
