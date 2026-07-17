<?php

declare(strict_types=1);

namespace App\Services\Analytics;

interface AnalyticsReadinessVerifier
{
    /**
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    public function verify(array $context): array;
}
