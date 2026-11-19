<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Nodes\RoleName;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Infrastructure\Engine\GatewayBus;
use App\Infrastructure\Engine\NatsCredentials;
use App\Models\Node;
use Carbon\Carbon;

/**
 * Which Node a machine bus connection is, and what it may do (ADR 0271; docs/reference/gateway-engine.md#authentication). A
 * Node holds no bus credential. `nats-server` hands each Node connection to the Gateway's auth callout, which applies the
 * Gateway API's rule: the connection's WireGuard address must belong to an active Node. The answer is a user JWT that may use
 * only that Node's subjects and lasts an hour; the client's reconnect then asks again, so a removed Node loses the bus.
 *
 * Every process on a Node shares its WireGuard address, so the address alone would admit an app or an agent shell as the
 * Node's executor. The guard on each Node therefore lets only root reach the bus: the boundary is root on the Node.
 */
final readonly class NodeBusAccess
{
    public const string Path = '/etc/orbit/executor/nats.json';

    public const string GuardPath = '/etc/orbit/executor/bus-guard.nft';

    /** The account every Node user joins: the one that holds the streams. */
    public const string Account = 'ORBIT';

    public const int LifetimeSeconds = 3600;

    /** The Gateway's bus address for an executor, or null when the Gateway has no engine key or no WireGuard address. */
    public static function url(): ?string
    {
        $address = GatewayBus::address();

        return $address === null ? null : 'nats://'.$address.':'.GatewayBus::port();
    }

    /**
     * The Node's nftables guard for the bus, or null without a bus: only root may open a connection to the Gateway's NATS port.
     * The executor's unit loads it before every start, replacing the previous table.
     */
    public static function guard(): ?string
    {
        $url = self::url();
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? ($parts['port'] ?? 4222) : 4222;

        return "# Managed by Orbit: only root may reach the machine bus (ADR 0271; docs/reference/gateway-engine.md#authentication).\n"
            ."destroy table inet orbit_bus_guard\ntable inet orbit_bus_guard {\n  chain output {\n"
            ."    type filter hook output priority 0; policy accept;\n"
            ."    ip daddr {$host} tcp dport {$port} meta skuid != 0 reject with tcp reset\n  }\n}\n";
    }

    /**
     * The callout's answer to one authorization request from `nats-server`: a signed response with the Node's user JWT, a
     * signed refusal, or '' for a request it cannot read, which the server treats as a refusal.
     */
    public static function authorize(string $request): string
    {
        $claims = NatsCredentials::claims($request);
        $nats = is_array($claims['nats'] ?? null) ? $claims['nats'] : [];
        $userKey = $nats['user_nkey'] ?? null;
        $server = is_array($nats['server_id'] ?? null) ? ($nats['server_id']['id'] ?? null) : null;
        $client = is_array($nats['client_info'] ?? null) ? $nats['client_info'] : [];
        if (! is_string($userKey) || $userKey === '' || ! is_string($server) || $server === '') {
            return '';
        }
        $seed = GatewayBus::seed();
        $now = Carbon::now()->getTimestamp();
        $host = is_string($client['host'] ?? null) ? $client['host'] : '';
        $node = RequireActiveWireGuardPeer::activeNodeAt($host);
        if (! $node instanceof Node) {
            logger()->warning('engine.bus_peer_refused', ['host' => $host]);

            return NatsCredentials::authorizationResponse($seed, $userKey, $server, $now, null, 'peer.identity_unknown');
        }
        $jwt = NatsCredentials::userJwt($seed, $userKey, 'node-'.$node->id, self::Account, self::publish($node->id), self::subscribe($node->id),
            $now, $now + self::LifetimeSeconds);

        return NatsCredentials::authorizationResponse($seed, $userKey, $server, $now, $jwt);
    }

    /**
     * Only the Node's own inbox prefix: replies to its own requests and its order deliveries. Another Node's replies, such as its
     * content, never reach it.
     *
     * @return list<string>
     */
    public static function subscribe(int $node): array
    {
        return ["_INBOX.node-{$node}.>"];
    }

    /**
     * Exactly what a Node's executor publishes: its own order consumer, acknowledgements, receipts, content and capability report.
     *
     * @return list<string>
     */
    public static function publish(int $node): array
    {
        return ['$JS.API.INFO', '$JS.API.STREAM.INFO.ORDERS', "\$JS.API.CONSUMER.INFO.ORDERS.node-{$node}", "\$JS.API.CONSUMER.MSG.NEXT.ORDERS.node-{$node}",
            "\$JS.ACK.ORDERS.node-{$node}.>", "orbit.receipts.{$node}", "\$JS.API.DIRECT.GET.KV_CONTENT.\$KV.CONTENT.n{$node}.>", "\$KV.NODES.n{$node}"];
    }
}
