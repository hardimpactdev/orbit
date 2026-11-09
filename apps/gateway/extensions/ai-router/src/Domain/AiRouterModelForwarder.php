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
 * demands the placeholder. The forwarder's own nftables table makes it the only way from the Node to the guard.
 */
final readonly class AiRouterModelForwarder
{
    public const string Kind = 'ai_router.model_forwarder';

    public const int Port = 8319;

    public const string Directory = '/etc/orbit/ai-router-forwarder';

    public const string Unit = 'orbit-ai-router-forwarder.service';

    public const string Comment = 'orbit:ai-router-forwarder';

    /** The fixed system user the forwarder runs as; the output rule names it. */
    public const string User = 'orbit-ai-router-forwarder';

    public const string Table = 'orbit_ai_router_forwarder';

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
     * The forwarder's own nftables table: traffic from the Node to the guard leaves from the Node's WireGuard address, which the guard
     * admits, so only the forwarder may send it.
     * - The forward chain drops every forwarded packet to the guard: a container on any bridge, an Incus or a Docker one. It runs before
     *   the `filter` priority, where Docker's `FORWARD` accept and UFW's chains sit, and a drop in any base chain is final.
     * - The output chain resets every connection of the Node's own processes to the guard but the forwarder's user's: root, `orbit`
     *   and every app process on the Node included.
     * The file replaces the table whole, so loading it again is idempotent.
     */
    public function ruleset(string $guardAddress): string
    {
        $table = self::Table;
        $user = self::User;
        $port = AiRouterTaskGuard::Port;

        return <<<NFT
            # Managed by Orbit: the ai-router container model forwarder (/reference/ai-router#container-model-forwarder).
            table inet {$table}
            delete table inet {$table}
            table inet {$table} {
            	chain forward {
            		type filter hook forward priority filter - 10; policy accept;
            		ip daddr {$guardAddress} tcp dport {$port} counter drop
            	}
            	chain output {
            		type filter hook output priority filter - 10; policy accept;
            		ip daddr {$guardAddress} tcp dport {$port} meta skuid != "{$user}" counter reject with tcp reset
            	}
            }

            NFT;
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
