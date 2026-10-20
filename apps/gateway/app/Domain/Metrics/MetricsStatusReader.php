<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Data\Metrics\MetricsStatusData;
use App\Infrastructure\Metrics\NativeMetricsStatusReader;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeMetricsStatusReader::class)]
interface MetricsStatusReader
{
    public function status(): MetricsStatusData;
}
