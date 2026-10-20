<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeMetricsAccessRevoker::class)]
interface MetricsAccessRevoker
{
    public function revoke(): void;
}
