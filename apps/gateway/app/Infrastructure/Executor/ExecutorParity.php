<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\Instances\Power\ExecutorPowerIntent;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\InstanceRuntime;
use App\Models\Node;
use Throwable;

/**
 * Executor build parity (ADR 0266, "Must clear before the signed power flags go on"; docs/reference/executor.md#build-parity).
 * Every Node whose roles enable an executor family must run the build that this Gateway pins: the converge intent's SHA-256 of
 * artifact `executor-linux-x86_64`. Each Node reports, over its pinned SSH account with one fixed read-only argv, the SHA-256 of its
 * installed binary, the SHA-256 of the service's running executable and the service state. The running executable needs root, so
 * it is read with `sudo -n`; a binary replaced without a restart shows as `executor.running_build_differs`.
 *
 * The same read lists the Node's containers that Incus reports FROZEN. One whose Instance has no recorded freeze, such as a failed
 * Python sleep that left it frozen while the Gateway records it running, has no owner that R3 could route its wake to: the Node
 * fails with `instance.frozen_unrecorded` until a wake with the flags off resumes it (docs/reference/executor.md#switching-engines).
 *
 * The same read also returns each Instance's activation proxy in the Node's loaded Caddy config, from the admin API: the handler
 * that a visit matched by the asleep marker reaches, with every address its upstream resolves to on the Node. Each must be what the
 * route renderer draws for its Instance (URI, upstream, GET, Host, TLS name) and reach only this Gateway's WireGuard address; else
 * the Node fails with `caddy.activation_proxy_wrong` and names each Instance (docs/reference/executor.md#activation-proxy).
 */
final readonly class ExecutorParity
{
    /**
     * Prints the installed binary's SHA-256, the running executable's SHA-256 (empty when none runs), the service state, then
     * `frozen:` and one line per FROZEN container. The `frozen:` line comes only when Incus answered, so a failed read is no pass.
     */
    private const string Read = 'sha256sum '.NodeExecutorInput::Binary.' 2>/dev/null | cut -d " " -f 1; '
        .'pid=$(systemctl show -p MainPID --value orbit-executor.service 2>/dev/null); '
        .'if [ "${pid:-0}" != 0 ]; then sudo -n sha256sum "/proc/$pid/exe" 2>/dev/null | cut -d " " -f 1; else echo; fi; '
        .'systemctl is-active orbit-executor.service 2>/dev/null; '
        .'if containers=$(sudo -n incus list --all-projects --format csv --columns ns 2>/dev/null); then echo frozen:; '
        .'printf "%s\n" "$containers" | grep ",FROZEN$" | cut -d , -f 1; fi; '
        .'if config=$(curl -fsS -m 5 http://127.0.0.1:2019/config/ 2>/dev/null) '
        .'&& proxies=$(printf "%s" "$config" | python3 -I -c \''.self::Proxies.'\'); then echo caddy:; printf "%s\n" "$proxies"; fi; true';

    /**
     * Finds each Instance's activation proxy the way the executor's Caddy guard does: by the route renderer's asleep-marker matcher
     * (`file` with `try_files /app-instance-<id>.asleep`), not by the rewrite. A site is one top-level route of a Caddy server,
     * named by its host matcher. For each site and Instance it prints one JSON line: the first handler other than `vars` that a
     * marked visit to that site reaches, without its response routes, with `site`, `instance` and `resolved`, every address its
     * upstreams' hosts resolve to on the Node (null when one does not resolve).
     */
    private const string Proxies = <<<'PY'
        import json, re, socket, sys
        handlers = {}
        def marker(route):
            for matcher in route.get("match") or []:
                files = matcher.get("file") if isinstance(matcher, dict) else None
                for name in (files or {}).get("try_files") or []:
                    found = re.fullmatch(r"/app-instance-([1-9][0-9]*)\.asleep", str(name))
                    if found:
                        return int(found.group(1))
            return None
        def walk(site, value):
            if isinstance(value, dict):
                instance = marker(value)
                if instance is not None:
                    handlers.setdefault((site, instance), []).extend(value.get("handle") or [])
                for child in value.values():
                    walk(site, child)
            elif isinstance(value, list):
                for child in value:
                    walk(site, child)
        servers = ((json.load(sys.stdin).get("apps") or {}).get("http") or {}).get("servers") or {}
        for server, config in sorted(servers.items()):
            for index, route in enumerate(config.get("routes") or []):
                hosts = [host for matcher in route.get("match") or [] for host in matcher.get("host") or []]
                walk(",".join(hosts) or f"{server} route {index}", route)
        for (site, instance), found in sorted(handlers.items()):
            handler = next((h for h in found if isinstance(h, dict) and h.get("handler") != "vars"), None) or {}
            proxy = {key: handler[key] for key in handler if key != "handle_response"}
            proxy["site"] = site
            proxy["instance"] = instance
            addresses = set()
            for upstream in proxy.get("upstreams") or []:
                try:
                    host = str(upstream.get("dial")).rsplit(":", 1)[0]
                    addresses.update(info[4][0] for info in socket.getaddrinfo(host, None, proto=socket.IPPROTO_TCP))
                except (OSError, UnicodeError, AttributeError):
                    addresses.add(None)
            proxy["resolved"] = sorted(addresses, key=str)
            print(json.dumps(proxy, sort_keys=True))
        PY;

    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private DevelopmentCaddyConfigRenderer $routes,
    ) {}

    /**
     * One row per executor Node, by Node id. `problem` is null when the Node runs the pinned build, holds no unrecorded freeze and
     * loads only right activation proxies; `frozen_unrecorded` lists the ids of those Instances, `activation_wrong` each wrong proxy.
     *
     * @return list<array{node_id: int, node: string, expected: ?string, reported: ?string, running: ?string, state: ?string, frozen_unrecorded: list<int>, activation_wrong: list<string>, problem: ?string}>
     */
    public function check(): array
    {
        $rows = [];
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereNotNull('wireguard_ip')
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->value('wireguard_ip');
        $nodes = Node::query()->with('roles')->where('status', LifecycleStatus::Active)->where('platform', 'linux')->orderBy('id')->get();
        foreach ($nodes as $node) {
            $intent = NodeExecutorInput::for($node);
            if ($intent === null) {
                continue;
            }
            $expected = is_string($intent['sha256']) ? $intent['sha256'] : null;
            [$reported, $running, $state, $frozen, $proxies, $readProblem] = $this->eligibility->allows($node) ? $this->read($node) : [null, null, null, [], null, 'executor.node_unmanaged'];
            $unrecorded = $this->unrecorded($node, $frozen);
            $wrong = $this->wrongProxies($proxies ?? [], is_string($gateway) ? $gateway : null);
            $problem = match (true) {
                is_string($intent['skip']) => $intent['skip'],
                is_string($intent['plan_error']) => $intent['plan_error'],
                $readProblem !== null => $readProblem,
                $reported === null => 'executor.missing',
                $reported !== $expected => 'executor.build_differs',
                $state !== 'active' => 'executor.inactive',
                $running !== $expected => 'executor.running_build_differs',
                $unrecorded !== [] => 'instance.frozen_unrecorded',
                $proxies === null => 'caddy.config_unreadable',
                $wrong !== [] => 'caddy.activation_proxy_wrong',
                default => null,
            };
            $rows[] = ['node_id' => $node->id, 'node' => $node->name, 'expected' => $expected, 'reported' => $reported, 'running' => $running,
                'state' => $state, 'frozen_unrecorded' => $unrecorded, 'activation_wrong' => $wrong, 'problem' => $problem];
        }

        return $rows;
    }

    /**
     * The Instances on the Node whose containers Incus reports FROZEN while the Gateway records no freeze that owns them.
     *
     * @param  list<string>  $containers
     * @return list<int>
     */
    private function unrecorded(Node $node, array $containers): array
    {
        if ($containers === []) {
            return [];
        }
        $runtimes = InstanceRuntime::query()->with('instance')->whereIn('container_name', $containers)
            ->whereHas('instance', static fn ($query) => $query->where('node_id', $node->id))->orderBy('instance_id')->get();

        return array_values($runtimes->filter(static fn (InstanceRuntime $runtime): bool => ExecutorPowerIntent::frozenSleep($runtime->instance, $runtime) === null)
            ->map(static fn (InstanceRuntime $runtime): int => $runtime->instance_id)->all());
    }

    /**
     * Each loaded activation proxy that differs from what the route renderer draws for its Instance, its rewrite included, or
     * whose upstream resolves to any address but this Gateway's WireGuard address, as `Instance <id> at <site>: <field> <loaded value>`.
     *
     * @param  list<array<mixed>>  $proxies
     * @return list<string>
     */
    private function wrongProxies(array $proxies, ?string $gateway): array
    {
        $wrong = [];
        foreach ($proxies as $proxy) {
            $instance = $proxy['instance'] ?? null;
            $site = is_string($proxy['site'] ?? null) ? $proxy['site'] : '?';
            $target = is_int($instance) ? $this->routes->activationTarget($instance) : null;
            if ($target === null) {
                continue;
            }
            $loaded = [
                'handler' => $proxy['handler'] ?? null,
                'URI' => data_get($proxy, 'rewrite.uri'),
                'upstream' => array_column(is_array($proxy['upstreams'] ?? null) ? $proxy['upstreams'] : [], 'dial'),
                'method' => data_get($proxy, 'rewrite.method'),
                'Host' => data_get($proxy, 'headers.request.set.Host'),
                'TLS server name' => data_get($proxy, 'transport.tls.server_name'),
                'resolves to' => $proxy['resolved'] ?? null,
            ];
            $want = ['handler' => 'reverse_proxy', 'URI' => $target['uri'], 'upstream' => [$target['dial']], 'method' => $target['method'], 'Host' => [$target['host']],
                'TLS server name' => $target['host'], 'resolves to' => [$gateway]];
            foreach ($want as $field => $value) {
                if ($loaded[$field] !== $value) {
                    $wrong[] = "Instance {$instance} at {$site}: {$field} ".json_encode($loaded[$field], JSON_UNESCAPED_SLASHES);
                }
            }
        }
        sort($wrong);

        return $wrong;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: list<string>, 4: ?list<array<mixed>>, 5: ?string} */
    private function read(Node $node): array
    {
        $unreachable = [null, null, null, [], null, 'executor.node_unreachable'];
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return $unreachable;
        }
        try {
            $result = $this->ssh->execute(new SshConnection($node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
                new RemoteCommand(['sh', '-c', self::Read], maxOutputBytes: 1048576, timeout: 15.0));
        } catch (Throwable) {
            return $unreachable;
        }
        if (! $result->succeeded() || $result->truncated) {
            return $unreachable;
        }
        $lines = explode("\n", rtrim($result->stdout));
        if (count($lines) < 4 || $lines[3] !== 'frozen:') {
            return $unreachable;
        }
        $sha = static fn (string $line): ?string => preg_match('/\A[0-9a-f]{64}\z/', $line) === 1 ? $line : null;
        $state = trim($lines[2]);
        $caddy = array_search('caddy:', $lines, true);
        $tail = array_slice($lines, 4, $caddy === false ? null : $caddy - 4);
        $frozen = array_values(array_filter(array_map(trim(...), $tail), static fn (string $name): bool => $name !== ''));
        $proxies = null;
        if ($caddy !== false) {
            $proxies = [];
            foreach (array_slice($lines, $caddy + 1) as $line) {
                $proxy = json_decode($line, true);
                if (is_array($proxy)) {
                    $proxies[] = $proxy;
                }
            }
        }

        return [$sha($lines[0]), $sha($lines[1]), $state === '' ? null : $state, $frozen, $proxies, null];
    }
}
