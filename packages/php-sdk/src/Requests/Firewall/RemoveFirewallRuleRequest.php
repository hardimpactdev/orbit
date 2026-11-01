<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

/** Removes the one rule that matches the port and, when given, the source and protocol. */
final class RemoveFirewallRuleRequest extends FirewallRuleRequest
{
    public function __construct(
        int $nodeId,
        string $port,
        ?string $source = null,
        ?string $protocol = null,
        ?string $idempotencyKey = null,
    ) {
        parent::__construct($nodeId, $port, $source, $protocol, idempotencyKey: $idempotencyKey);
    }

    protected function action(): string
    {
        return 'remove';
    }
}
