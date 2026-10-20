<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Firewall\FirewallInspectionTarget;
use App\Infrastructure\Metrics\NativeMetricsFirewallExpectationProvider;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeMetricsFirewallExpectationProvider::class)]
interface MetricsFirewallExpectationProvider
{
    /** @return list<FirewallInspectionTarget> */
    public function for(Node $node): array;
}
