<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Infrastructure\Analytics\NativeAnalyticsStatsKeyStore;
use Illuminate\Container\Attributes\Bind;
use SensitiveParameter;

/** The fleet Stats API key the analytics driver sends to Plausible. Never returned by the API. */
#[Bind(NativeAnalyticsStatsKeyStore::class)]
interface AnalyticsStatsKeyStore
{
    public function get(): ?string;

    public function put(#[SensitiveParameter] string $key): void;

    public function clear(): void;

    public function configured(): bool;
}
