<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;

/**
 * Names the Gateway's own browser origin that may call its API
 * ([ADR 0126](/decisions/0126-limit-browser-api-calls-to-orbit-origins)).
 */
final readonly class BrowserOriginPolicy
{
    public function __construct(private VpnSettings $vpnSettings) {}

    public function allows(string $origin): bool
    {
        $host = $this->httpsHost($origin);

        if ($host === null) {
            return false;
        }

        return $host === $this->gatewayHost();
    }

    /** The lowercase host of an `https://host[:443]` origin, or null for anything else. */
    private function httpsHost(string $origin): ?string
    {
        if (preg_match('#^https://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::443)?\z#', strtolower($origin), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function gatewayHost(): ?string
    {
        $name = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query
                ->where('role', RoleName::Gateway)
                ->where('status', LifecycleStatus::Active))
            ->value('name');

        return is_string($name) ? strtolower("{$name}.{$this->vpnSettings->domain()}") : null;
    }
}
