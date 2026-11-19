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
 */
final readonly class NodeBusAccess
{
    public const string Path = '/etc/orbit/executor/nats.json';

    /** The account every Node user joins: the one that holds the streams. */
    public const string Account = 'ORBIT';

    public const int LifetimeSeconds = 3600;

    /** The Gateway's bus address for an executor, or null when the Gateway has no engine key or no WireGuard address. */
    public static function url(): ?string
    {
        if (! GatewayBus::enabled()) {
            return null;
        }
        $gateway = Node::query()->whereHas('roles', static fn ($roles) => $roles->where('role', RoleName::Gateway->value))->value('wireguard_ip');
        if (! is_string($gateway) || $gateway === '') {
            return null;
        }
        $port = config('orbit.engine.nats.port');

        return 'nats://'.$gateway.':'.(is_int($port) ? $port : 4222);
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
