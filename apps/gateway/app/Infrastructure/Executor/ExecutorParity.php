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
 * The same read also returns every activation proxy in the Node's loaded Caddy config, from the admin API, with the address its
 * upstream name resolves to on the Node. Each must be what the route renderer draws for its Instance (upstream, GET, Host, TLS
 * name) and must reach this Gateway's WireGuard address; else the Node fails with `caddy.activation_proxy_wrong` and names each
 * Instance (docs/reference/executor.md#activation-proxy). The executor's own guard checks only the handler and its URI.
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
     * Prints, as one JSON line each, every reverse_proxy of the loaded Caddy config whose rewrite targets a runtime activation,
     * without its response routes, and with `resolved`: the address each upstream's host resolves to on the Node.
     */
    private const string Proxies = <<<'PY'
        import json, socket, sys
        stack = [json.load(sys.stdin)]
        while stack:
            value = stack.pop()
            if isinstance(value, dict):
                rewrite = value.get("rewrite")
                if value.get("handler") == "reverse_proxy" and isinstance(rewrite, dict) and "/runtime-activations/" in str(rewrite.get("uri")):
                    proxy = {key: value[key] for key in value if key != "handle_response"}
                    proxy["resolved"] = []
                    for upstream in value.get("upstreams") or []:
                        try:
                            proxy["resolved"].append(socket.gethostbyname(str(upstream.get("dial")).rsplit(":", 1)[0]))
                        except (OSError, UnicodeError):
                            proxy["resolved"].append(None)
                    print(json.dumps(proxy, sort_keys=True))
                stack.extend(value.values())
            elif isinstance(value, list):
                stack.extend(value)
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
     * Each loaded activation proxy that differs from what the route renderer draws for its Instance, or whose upstream does not
     * resolve to this Gateway's WireGuard address, as `Instance <id>: <field> <loaded value>`.
     *
     * @param  list<array<mixed>>  $proxies
     * @return list<string>
     */
    private function wrongProxies(array $proxies, ?string $gateway): array
    {
        $wrong = [];
        foreach ($proxies as $proxy) {
            $uri = data_get($proxy, 'rewrite.uri');
            if (! is_string($uri) || preg_match('#/app-instance/([1-9][0-9]*)\z#', $uri, $matches) !== 1) {
                continue;
            }
            $target = $this->routes->activationTarget((int) $matches[1]);
            if ($target === null || $target['uri'] !== $uri) {
                continue;
            }
            $loaded = [
                'upstream' => array_column(is_array($proxy['upstreams'] ?? null) ? $proxy['upstreams'] : [], 'dial'),
                'method' => data_get($proxy, 'rewrite.method'),
                'Host' => data_get($proxy, 'headers.request.set.Host'),
                'TLS server name' => data_get($proxy, 'transport.tls.server_name'),
                'resolves to' => $proxy['resolved'] ?? null,
            ];
            $want = ['upstream' => [$target['dial']], 'method' => $target['method'], 'Host' => [$target['host']],
                'TLS server name' => $target['host'], 'resolves to' => [$gateway]];
            foreach ($want as $field => $value) {
                if ($loaded[$field] !== $value) {
                    $wrong[] = "Instance {$matches[1]}: {$field} ".json_encode($loaded[$field], JSON_UNESCAPED_SLASHES);
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
