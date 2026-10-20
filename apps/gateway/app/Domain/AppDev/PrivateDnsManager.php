<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Models\Node;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Singleton;

#[Bind(DnsmasqPrivateDnsManager::class)]
#[Singleton]
interface PrivateDnsManager
{
    public function converge(?Node $pendingNode = null): void;
}
