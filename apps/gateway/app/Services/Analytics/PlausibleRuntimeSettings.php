<?php

declare(strict_types=1);

namespace App\Services\Analytics;

final readonly class PlausibleRuntimeSettings
{
    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @param  array<string, mixed>  $credentials
     */
    public function __construct(
        public array $runtimeConfig,
        public array $credentials,
    ) {}
}
