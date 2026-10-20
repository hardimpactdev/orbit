<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\NativeUfwFirewallInspector;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeUfwFirewallInspector::class)]
interface FirewallInspector
{
    /** @param non-empty-list<FirewallInspectionTarget> $targets */
    public function inspect(array $targets): FirewallInspectionBatchData;
}
