<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Models\Node;

/** Desired fleet state decides whether the Gateway must serve its own private DNS. */
final readonly class GatewayPrivateDnsInput
{
    public function __construct(private GatewayPrivateDnsResolver $resolver, private VpnSettings $settings, private GatewayPrivateDnsState $state) {}

    public function requiresLocalListener(Node $gateway): bool
    {
        return Node::query()->whereKeyNot($gateway->id)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])
            ->whereHas('roles', static fn ($roles) => $roles->where('role', RoleName::Vpn)
                ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning]))
            ->doesntExist();
    }

    /** @return array<string,mixed>|null */
    public function capture(Node $gateway): ?array
    {
        $local = $this->requiresLocalListener($gateway);
        $address = $local ? $gateway->wireguard_ip : $this->resolver->vpnDnsAddress($this->settings);
        if ($address === null) {
            return null;
        }
        $dns = ['operation' => 'converge', 'address' => $address, 'domain' => $this->settings->domain()];
        if ($local) {
            $dns['listener'] = app(NodeConvergeInput::class)->privateDns($gateway, [
                'listener' => app(NodePrivateDnsListenerFootprint::class)->input($gateway),
                'publication' => app(NodeConvergeInput::class)->dns(),
            ]);
        } elseif ($this->state->owned($gateway->id) && ! $gateway->roles()->where('role', RoleName::Vpn)->exists()) {
            $dns['withdraw'] = ['resolver_address' => $address, 'local_address' => $gateway->wireguard_ip,
                'units' => ['orbit-private-dns.socket', 'orbit-private-dns.service'],
                'files' => ['/etc/systemd/system/orbit-private-dns.socket', '/etc/systemd/system/orbit-private-dns.service',
                    '/var/lib/orbit/private-dns/catalog.json', '/var/lib/orbit/private-dns/catalog.json.loaded'],
                'releases' => '/var/lib/orbit/private-dns/releases'];
        }

        return $dns;
    }
}
