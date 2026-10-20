<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\NativeUfwFirewallManager;
use App\Models\FirewallRule;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeUfwFirewallManager::class)]
interface FirewallManager
{
    public function converge(FirewallRule $rule): FirewallBackendStatus;

    public function remove(FirewallRule $rule): FirewallBackendStatus;
}
