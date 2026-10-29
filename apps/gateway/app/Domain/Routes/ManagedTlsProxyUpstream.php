<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\RouteCustomProxy;

/** A stored service relocation uses existing managed connectivity; caller URLs stay loopback-only. */
final readonly class ManagedTlsProxyUpstream
{
    public static function authority(Node $serving, RouteCustomProxy $proxy): string
    {
        $parts = parse_url($proxy->upstream);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null) || ! is_int($parts['port'] ?? null)
            || $parts['port'] < 1
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || filter_var($parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw self::invalid();
        }
        $target = $proxy->node;
        if ($target?->status !== LifecycleStatus::Active || $target->wireguard_ip !== $parts['host']) {
            throw self::invalid();
        }
        if (! NodeAccess::query()->where('consumer_node_id', $serving->id)
            ->where('serving_node_id', $target->id)->exists()) {
            throw self::invalid();
        }

        return $parts['host'].':'.$parts['port'];
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('route.upstream_invalid', 'A stored TLS proxy must use an accessible active Node WireGuard listener.');
    }
}
