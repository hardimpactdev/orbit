<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AdmitNodeWireGuardHubOperationAction;
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

        return ['config' => "[Interface]\nAddress = {$field('address')}\nPostUp = wg set %i private-key ".AdmitNodeWireGuardHubOperationAction::KeyFile."\n{$hook}\n\n".$peers,
            'peers' => $peers, 'dns_hook' => $hook, 'dns_marker' => implode("\n", ['orbit', $field('dns_server'), ...$domains])."\n"];
    }

    /** @return array<string,mixed> */
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
            'hub' => AdmitNodeWireGuardHubOperationAction::joining($hub, $node->id, $node->name, $address),
            'other_public_keys' => array_values(Node::query()->whereKeyNot($node->id)
                ->whereNotNull('wireguard_public_key')->pluck('wireguard_public_key')->all()),
        ];
    }
}
