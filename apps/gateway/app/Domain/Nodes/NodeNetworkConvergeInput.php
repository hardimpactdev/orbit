<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Support\ValidatedData;

/**
 * Public admission for the direct 209 SSH root. The Gateway renders every WireGuard file: the peer's wg-quick file and
 * peer list, and the hub's files with the peer's section left for its prepared public key. No private key is in any.
 */
final readonly class NodeNetworkConvergeInput
{
    /** Public desired state for the network part of node.converge. No Node contact.
     * @return array<string,mixed>
     */
    public function converge(Node $node): array
    {
        $hub = $node->roles()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->exists();
        if ($hub) {
            $apply = NodeWireGuardHubInput::intent($node);
            $peers = array_map(static fn (array $peer): array => self::section($peer['content']), $apply['peers']);

            return ['mode' => 'hub', 'hub_id' => $node->id, 'apply' => $apply,
                'observations' => [['public_key' => $node->wireguard_public_key, 'port' => $apply['port'], 'peers' => $peers, 'exact' => true]],
                'endpoint_host' => null, 'probe_ip' => null];
        }
        $apply = $this->capture($node, ! $node->roles()->exists());
        $peer = ValidatedData::object($apply['peer']);
        $endpoint = ValidatedData::string($peer['endpoint']);
        $portAt = strrpos($endpoint, ':');
        if ($portAt === false) {
            throw new \LogicException('The rendered endpoint has no port.');
        }
        $host = trim(substr($endpoint, 0, $portAt), '[]');
        $port = (int) substr($endpoint, $portAt + 1);

        return ['mode' => 'peer', 'hub_id' => $apply['hub_id'], 'apply' => $apply,
            'observations' => [
                ['public_key' => $apply['existing_key'], 'port' => null, 'exact' => true,
                    'peers' => [['public_key' => $peer['server_public_key'], 'allowed_ips' => [$peer['subnet']], 'endpoint_port' => $port, 'keepalive' => 25]]],
                ['public_key' => $peer['server_public_key'], 'port' => null, 'exact' => false,
                    'peers' => [['public_key' => $apply['existing_key'], 'allowed_ips' => [explode('/', ValidatedData::string($peer['address']))[0].'/32']]]],
            ], 'endpoint_host' => $host, 'probe_ip' => $apply['hub']['hub_id'] === $node->id ? $node->wireguard_ip : Node::query()->findOrFail($apply['hub_id'])->wireguard_ip];
    }

    /** @return array{public_key:string,allowed_ips:list<string>} */
    private static function section(string $content): array
    {
        if (preg_match('/^PublicKey = (.+)$/m', $content, $key) !== 1 || preg_match('/^AllowedIPs = (.+)$/m', $content, $allowed) !== 1) {
            throw new \LogicException('A rendered hub peer has no key or allowed IPs.');
        }

        return ['public_key' => $key[1], 'allowed_ips' => array_map(trim(...), explode(',', $allowed[1]))];
    }

    /**
     * The split-DNS hook that wg-quick runs on start; fleet.peer_dns keeps the same line.
     *
     * @param  list<string>  $domains
     */
    public static function dnsHook(string $server, array $domains): string
    {
        return "PostUp = resolvectl dns %i {$server}; resolvectl domain %i ".implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $domains));
    }

    /**
     * A peer's WireGuard files from its captured policy: the wg-quick file, the peer list `wg syncconf` loads, the
     * split-DNS hook line and the DNS marker. None holds a private key; `PostUp` loads the key file.
     *
     * @param  array<string,mixed>  $peer
     * @return array{config:string,peers:string,dns_hook:string,dns_marker:string}
     */
    public static function peerFiles(array $peer): array
    {
        $field = static fn (string $key): string => ValidatedData::string($peer[$key] ?? null);
        $domains = ValidatedData::stringList($peer['domains'] ?? null);
        $hook = self::dnsHook($field('dns_server'), $domains);
        $peers = "[Peer]\nPublicKey = {$field('server_public_key')}\nEndpoint = {$field('endpoint')}\nAllowedIPs = {$field('subnet')}\nPersistentKeepalive = 25\n";

        return ['config' => "[Interface]\nAddress = {$field('address')}\nPostUp = wg set %i private-key ".NodeWireGuardHubInput::KeyFile."\n{$hook}\n\n".$peers,
            'peers' => $peers, 'dns_hook' => $hook, 'dns_marker' => implode("\n", ['orbit', $field('dns_server'), ...$domains])."\n"];
    }

    /** @return array{ssh:array<string,mixed>,hub_id:int,existing_key:?string,peer:array<string,mixed>,trust_argv:list<string>,config:string,peers:string,dns_hook:string,dns_marker:string,hub:array<string,mixed>,other_public_keys:list<string>} */
    public function capture(Node $node, bool $rolelessOperator): array
    {
        $network = app(NodeNetworkInput::class)->capturePublic($node, $rolelessOperator);
        $hub = Node::query()->findOrFail(ValidatedData::integer($network['hub_id']));
        if ($hub->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('node.network_unsupported', 'The VPN owner must be active.', 422);
        }
        $ssh = app(SwampSshExecutionInput::class);
        $trust = app(NodeFirewallRuleCatalog::class)->forNode($node)[1];

        $peer = ValidatedData::object($network['peer']);
        $address = explode('/', ValidatedData::string($peer['address'] ?? null))[0];

        return ['ssh' => $ssh->forNode($node), 'hub_id' => $hub->id, 'existing_key' => $node->wireguard_public_key,
            'peer' => $peer, 'trust_argv' => ['ufw', ...array_slice($trust->arguments, 2)],
            ...self::peerFiles($peer),
            'hub' => NodeWireGuardHubInput::joining($hub, $node->id, $node->name, $address),
            'other_public_keys' => ValidatedData::stringList(array_values(Node::query()->whereKeyNot($node->id)
                ->whereNotNull('wireguard_public_key')->pluck('wireguard_public_key')->all())),
        ];
    }
}
