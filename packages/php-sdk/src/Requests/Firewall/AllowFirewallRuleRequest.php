<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

final class AllowFirewallRuleRequest extends FirewallRuleRequest
{
    protected function action(): string
    {
        return 'allow';
    }
}
