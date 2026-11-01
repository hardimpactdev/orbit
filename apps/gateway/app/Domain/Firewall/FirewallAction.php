<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

enum FirewallAction: string
{
    case Allow = 'allow';
    case Allowlist = 'allowlist';
    case Deny = 'deny';
}
