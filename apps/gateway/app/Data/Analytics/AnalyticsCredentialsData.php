<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Presence metadata only; credential values never leave this endpoint. */
#[MapOutputName(SnakeCaseMapper::class)]
final class AnalyticsCredentialsData extends Data
{
    public function __construct(
        public bool $configured,
        public string $driver,
        public bool $managementEmailConfigured,
        public bool $managementPasswordConfigured,
    ) {}
}
