<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Infrastructure\Engine\NatsCredentials;
use App\Domain\Nodes\RoleName;
use App\Models\Node;

/**
 * A Node executor's machine bus settings (ADR 0271; docs/reference/gateway-engine.md#credentials): the Gateway's NATS address
 * over WireGuard and a user JWT that may use only this Node's subjects. The converge writes them to
 * `/etc/orbit/executor/nats.json`, root only. A Gateway without the engine's keys writes nothing, and the executor serves its
 * socket alone.
 */
final readonly class NodeBusCredentials
{
    public const string Path = '/etc/orbit/executor/nats.json';

    /** The settings file's content, or null when the Gateway has no engine keys or no WireGuard address. */
    public static function for(Node $node): ?string
    {
        $account = config('orbit.engine.nats.account_seed');
        $gateway = Node::query()->whereHas('roles', static fn ($roles) => $roles->where('role', RoleName::Gateway->value))->value('wireguard_ip');
        if (! is_string($account) || $account === '' || ! is_string($gateway) || $gateway === '') {
            return null;
        }
        $port = config('orbit.engine.nats.port');
        $name = 'node-'.$node->id;
        $seed = NatsCredentials::userSeed($account, $name);
        $jwt = NatsCredentials::userJwt($account, NatsCredentials::publicKey($seed), $name, self::publish($node->id), ['_INBOX.>']);

        return json_encode(['url' => 'nats://'.$gateway.':'.(is_int($port) ? $port : 4222), 'credentials' => NatsCredentials::creds($jwt, $seed)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
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
