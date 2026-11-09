<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * The container model forwarder ([ADR 0252](/decisions/0252-reach-the-model-router-from-containers-through-a-node-forwarder)): its own
 * Caddy on an agent Node's container bridge address at port 8319, which proxies every request to the task VM guard with only Caddy's
 * `X-Forwarded-*` headers added. It holds no key and has no rule of its own; the guard admits the Node's WireGuard address and still
 * demands the placeholder. UFW drops forwarded traffic to the guard on the Node, so a container reaches it only through the forwarder.
 */
final readonly class AiRouterModelForwarder
{
    public const string Kind = 'ai_router.model_forwarder';

    public const int Port = 8319;

    public const string Directory = '/etc/orbit/ai-router-forwarder';

    public const string Unit = 'orbit-ai-router-forwarder.service';

    public const string Comment = 'orbit:ai-router-forwarder';

    public const string RouteComment = 'orbit:ai-router-forwarder-route';

    /** The origin containers on the Node call. */
    public function origin(string $address): string
    {
        return 'http://'.$address.':'.self::Port;
    }

    public function caddyfile(string $address, string $guardOrigin): string
    {
        $port = self::Port;

        return <<<CADDY
            # Managed by Orbit: the ai-router container model forwarder (/reference/ai-router#container-model-forwarder). No key: the guard decides.
            {
            	admin off
            	persist_config off
            	auto_https off
            }

            http://:{$port} {
            	bind {$address}
            	reverse_proxy {$guardOrigin}
            }

            CADDY;
    }

    /** Port 8319 in on the container bridge only. */
    public function rule(string $bridge, string $address): UfwManagedRule
    {
        $port = (string) self::Port;

        return new UfwManagedRule(new UfwRuleShape(self::Comment, 'allow', 'in', 'any', $address, $port, 'tcp', $bridge, null, 'v4'),
            ['sudo', 'ufw', 'allow', 'in', 'on', $bridge, 'proto', 'tcp', 'from', 'any', 'to', $address, 'port', $port, 'comment', self::Comment]);
    }

    /**
     * Forwarded traffic to the guard dropped on the Node, from any bridge: a container's packets would leave from the Node's WireGuard
     * address, which the guard admits, so without it a container could skip the forwarder. The Node's own processes are not forwarded.
     */
    public function routeRule(string $guardAddress): UfwManagedRule
    {
        $port = (string) AiRouterTaskGuard::Port;

        return new UfwManagedRule(new UfwRuleShape(self::RouteComment, 'deny', 'fwd', 'any', $guardAddress, $port, 'tcp', null, null, 'v4'),
            ['sudo', 'ufw', 'route', 'deny', 'proto', 'tcp', 'from', 'any', 'to', $guardAddress, 'port', $port, 'comment', self::RouteComment]);
    }

    /**
     * The forwarder part of each Node whose last succeeded run on the router's guard left a forwarder in place, keyed and ordered by
     * Node ID.
     *
     * @return array<int, array{node: Node, bridge: string, address: string}>
     */
    public function present(Node $router): array
    {
        $present = [];
        foreach ($this->latest($router, succeeded: true) as $nodeId => $forwarder) {
            $node = Node::query()->find($nodeId);
            // A Node that went away or lost its address drops out; the next guard run removes its allow.
            if ($node !== null && $node->status === LifecycleStatus::Active && $forwarder['state'] === 'present'
                && is_string($node->wireguard_ip) && filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $present[$nodeId] = ['node' => $node, 'bridge' => $forwarder['bridge'], 'address' => $forwarder['address']];
            }
        }

        ksort($present);

        return $present;
    }

    /**
     * The bridge and address of the Node's last forwarder run of any status, so a removal also clears a run that failed halfway.
     *
     * @return array{bridge: string, address: string}|null
     */
    public function last(Node $router, Node $node): ?array
    {
        $forwarder = $this->latest($router, succeeded: false)[$node->id] ?? null;

        return $forwarder === null ? null : ['bridge' => $forwarder['bridge'], 'address' => $forwarder['address']];
    }

    /** @return array<int, array{state: string, bridge: string, address: string}> */
    private function latest(Node $router, bool $succeeded): array
    {
        $latest = [];
        $runs = Operation::query()->where('kind', self::Kind)->where('node_id', $router->id)
            ->when($succeeded, static fn ($query) => $query->where('status', OperationStatus::Succeeded))->orderByDesc('id')->get();
        foreach ($runs as $run) {
            $forwarder = ValidatedData::object(ValidatedData::object($run->input['intent'])['forwarder']);
            $latest[$run->target_id] ??= ['state' => ValidatedData::string($forwarder['state']),
                'bridge' => ValidatedData::string($forwarder['bridge']), 'address' => ValidatedData::string($forwarder['address'])];
        }

        return $latest;
    }
}
