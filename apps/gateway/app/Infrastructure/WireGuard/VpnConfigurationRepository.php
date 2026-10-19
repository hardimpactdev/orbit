<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Models\Node;
use Closure;
use InvalidArgumentException;

final readonly class VpnConfigurationRepository
{
    public function __construct(
        private VpnSettings $settings,
        private string $orbitHome,
    ) {}

    public function forPeer(Node $peer): VpnConfiguration
    {
        $server = Node::query()
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn->value))
            ->first();

        if (! $server instanceof Node || ! is_string($server->wireguard_ip)) {
            throw $this->invalid('The VPN role has no configured server node.');
        }

        if (! is_string($peer->wireguard_ip)) {
            throw $this->invalid("Node [{$peer->name}] has no WireGuard address.");
        }

        $subnet = $this->subnet();
        $prefixLength = $subnet->prefixLength();
        $port = filter_var($this->settings->port(), FILTER_VALIDATE_INT);

        if (! is_int($port) || $port < 1 || $port > 65_535) {
            throw $this->invalid('The WireGuard port is invalid.');
        }

        $serverAddress = $server->wireguard_ip;
        $peerAddress = $peer->wireguard_ip;

        if (! $subnet->containsUsableAddress($serverAddress)) {
            throw $this->invalid(
                "Node [{$server->name}] has invalid WireGuard address [{$serverAddress}] for subnet [{$subnet->value()}].",
            );
        }

        if (! $subnet->containsUsableAddress($peerAddress)) {
            throw $this->invalid(
                "Node [{$peer->name}] has invalid WireGuard address [{$peerAddress}] for subnet [{$subnet->value()}].",
            );
        }

        $serverPrivateKey = $this->key('private');
        $serverPublicKey = $this->key('public');
        $endpoint =
            $peer->wireguard_endpoint_override ?? $this->settings->endpoint() ?? WireGuardEndpoint::format(
                $server->public_ssh_host,
                $port,
            );
        $domain = $this->settings->domain();

        if (! WireGuardEndpoint::isValid($endpoint)) {
            throw $this->invalid('The WireGuard endpoint is invalid.');
        }

        $dnsServer = app(PeerDnsPolicy::class)->server($peer, $serverAddress);

        if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw $this->invalid('The private DNS domain is invalid.');
        }

        return new VpnConfiguration(
            server: $server,
            subnet: $subnet->value(),
            prefixLength: $prefixLength,
            port: $port,
            endpoint: $endpoint,
            dnsServer: $dnsServer,
            domain: $domain,
            serverAddress: "{$serverAddress}/{$prefixLength}",
            peerAddress: "{$peerAddress}/{$prefixLength}",
            serverPrivateKey: $serverPrivateKey,
            serverPublicKey: $serverPublicKey,
        );
    }

    /**
     * Serialize collision checking and assignment, including a reprovisioning rollback transaction.
     *
     * @param  (Closure(): void)|null  $save  Saves the desired key and any accompanying state before releasing the lock.
     */
    public function assignPeerKey(Node $node, string $publicKey, ?Closure $save = null): void
    {
        $directory = rtrim($this->orbitHome, '/').'/locks';

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw $this->invalid('The WireGuard key assignment lock is unavailable.');
        }

        $lock = fopen($directory.'/wireguard-peer-keys.lock', 'c+');

        if ($lock === false) {
            throw $this->invalid('The WireGuard key assignment lock is unavailable.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw $this->invalid('The WireGuard key assignment lock is unavailable.');
            }

            $hubKeyPath = rtrim($this->orbitHome, '/').'/wireguard/public.key';
            $hubKey = is_file($hubKeyPath) ? file_get_contents($hubKeyPath) : false;

            if ((is_string($hubKey) && trim($hubKey) === $publicKey)
                || Node::query()->whereKeyNot($node->id)->where('wireguard_public_key', $publicKey)->exists()) {
                throw new ResourceOperationException(
                    'node.wireguard_key_conflict',
                    'The WireGuard public key is already assigned to another peer or the hub.',
                    409,
                );
            }

            if ($save !== null) {
                $save();
            } else {
                $node->update(['wireguard_public_key' => $publicKey]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function subnet(): Ipv4Subnet
    {
        $value = $this->settings->subnet();

        try {
            return Ipv4Subnet::from($value);
        } catch (InvalidArgumentException) {
            throw $this->invalid("WireGuard subnet [{$value}] is invalid.");
        }
    }

    private function key(string $name): string
    {
        $path = rtrim(string: $this->orbitHome, characters: '/')."/wireguard/{$name}.key";
        $key = file_get_contents($path);

        if (! is_string($key) || trim($key) === '') {
            throw $this->invalid("WireGuard key [{$path}] is missing.");
        }

        return trim($key);
    }

    private function invalid(string $message): NodeProvisioningException
    {
        return new NodeProvisioningException(
            step: 'wireguard-configuration',
            errorCode: 'vpn.configuration_invalid',
            message: $message,
        );
    }
}
