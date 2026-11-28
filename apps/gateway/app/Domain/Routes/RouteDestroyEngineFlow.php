<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineSkip;
use App\Domain\Engine\EngineStepRefusal;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\EngineWait;
use App\Domain\Engine\StepSpec;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\RouteWriteNodes;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;
use RuntimeException;

/**
 * `route:destroy` on the state machine (ADR 0271; docs/cli/route/engine/destroy.mmd), composed of host primitives. It reads the
 * withdrawal plan the Gateway admitted (`route_lifecycle.plan`, NativeRouteRemovalSteps) and the digests of the files it was
 * rendered from (`route_guards`):
 *
 * - `dnsread-<i>-records`, `dnsread-<i>-catalog`: `file.read` of each VPN Node's dnsmasq records and private DNS catalog;
 * - `dnsunlink-<i>-records`, `dnsunlink-<i>-catalog`: `file.ensure` absent of a link a read found in a file's place, only then;
 * - `dns-<i>`: `config.apply` of both files under the DNS lock, guarded by the digests the Gateway rendered from at admission;
 *   dnsmasq tests them and both DNS services restart;
 * - `grace`: the Gateway waits 31 s from the last DNS receipt, so resolvers that honour the TTL stop asking first ([EngineWait]);
 * - `caddyread-<node>`: `file.read` of the Caddyfile on each Node that served the Route;
 * - `guard-<node>`: the Gateway refuses a Caddyfile that holds neither the render it captured at admission nor the desired one;
 * - `caddyunlink-<node>`: `file.ensure` absent of a link in the Caddyfile's place, only then;
 * - `withdraw-<node>`: `config.apply` of the Caddyfile under the Node's publication lock, validated and reloaded;
 * - `poll-<node>`: `http.probe` of Caddy's admin API until no withdrawn name is in its live configuration;
 * - `sibling-<node>`: a TLS handshake with another Route on that Node, so Caddy still serves the rest.
 *
 * Each read's receipt is the step's output, so a retry sends the same guard. `--offline`,
 * a replacement, LAN rules, the Ingress edge, any URL write, a production placement, access rows, and leaf folders or pools (the
 * account's home and group come from `account.read`, agent 0.37.0) start on Swamp ([EngineUnsupported]). Each step maps onto the
 * Swamp chart step it is part of, so the projection records the same `failed_step` and that step's error code.
 */
final readonly class RouteDestroyEngineFlow implements EngineDefinition, EngineSkip, EngineWait
{
    public const string Kind = 'route.destroy';

    /** The input a VPN Node must take for instance.destroy's Route steps: per-file digests of what the Gateway rendered from. */
    public const string GuardedDns = 'dns.guarded_records';

    /** The executor's code for a pool that failed its test or reload, which the Swamp chart's pool step reports. */
    public const string PoolFailed = 'route.pool_failed';

    /** The DNS grace: the records' TTL of 30 s and one more second. */
    public const int GraceMs = 31_000;

    /** The files, locks and services of a Node's publication: they arrive in every order, never in the executor. */
    public const string Caddyfile = '/etc/caddy/Caddyfile';

    public const string CaddyLock = '/etc/caddy/.orbit-publish.lock';

    public const string Records = '/etc/dnsmasq.d/orbit-records.conf';

    public const string Catalog = '/var/lib/orbit/private-dns/catalog.json';

    public const string DnsLock = '/run/lock/orbit-dnsmasq.lock';

    /** The executor's recovery records: one stable folder per Operation and step, outside every managed path. */
    public const string Recovery = '/var/lib/orbit/executor/recovery';

    /** Caddy's admin endpoint on the Node, read back after the reload, for about 5 s at most (ORB-6335). */
    public const string Admin = 'http://127.0.0.1:2019/config/';

    public const int AdminBudgetMs = 5_000;

    /** Each step prefix's Swamp chart label (NativeRouteRemovalProjection::StepCodes): a read and a check belong to their write. */
    private const array Labels = ['dns' => 'Remove private DNS', 'dnsread' => 'Remove private DNS', 'dnsunlink' => 'Remove private DNS',
        'grace' => 'Wait the DNS grace', 'caddyread' => 'Withdraw site, remove cert', 'guard' => 'Withdraw site, remove cert',
        'caddyunlink' => 'Withdraw site, remove cert', 'withdraw' => 'Withdraw site, remove cert',
        'poll' => 'Withdraw site, remove cert', 'sibling' => 'Check nothing is left'];

    public function __construct(private NativeRouteRemovalProjection $projection, private RouteEngineFlow $create,
        private DevelopmentSiteRepository $sites) {}

    public function steps(Operation $operation): array
    {
        $plan = self::plan($operation);
        self::domains($operation);
        // A public Route, or a Process proxy behind the Ingress, has a placement this flow does not withdraw (captured at admission).
        if (($operation->input['route_private_development'] ?? false) !== true) {
            throw new EngineUnsupported('Only a private development Route\'s removal runs on the engine.');
        }
        if (($operation->input['offline'] ?? false) !== false || ($plan['offline'] ?? false) !== false) {
            throw new EngineUnsupported('route.destroy --offline runs on Swamp.');
        }
        if (isset($operation->input['production_control'])) {
            throw new EngineUnsupported('A production release runs on Swamp.');
        }
        foreach (RouteExecutionProof::objects(ValidatedData::object($operation->input['route_removal'] ?? [])['nodes'] ?? []) as $member) {
            if (($member['production_instance_ids'] ?? []) !== []) {
                throw new EngineUnsupported('A Route of a production Instance runs on Swamp.');
            }
        }
        foreach (['lan_remove', 'ingress_close', 'lan_absent'] as $list) {
            if (($plan[$list] ?? []) !== []) {
                throw new EngineUnsupported("route.destroy with {$list} runs on Swamp.");
            }
        }
        $retire = ValidatedData::object($plan['retire']);
        foreach (['url_lines', 'env_files', 'lan', 'acl', 'links'] as $list) {
            if (($retire[$list] ?? []) !== []) {
                throw new EngineUnsupported("route.destroy with {$list} runs on Swamp.");
            }
        }
        // The account's home and group, which a leaf folder and a pool need, come from account.read (agent 0.37.0).
        if (RouteExecutionProof::objects($plan['cleanup']) !== [] || RouteExecutionProof::objects(ValidatedData::object($plan['pools'])['php_files'] ?? []) !== []) {
            throw new EngineUnsupported('A withdrawal that needs the account\'s home or group runs on Swamp.');
        }
        // A Node can appear twice: once for its sites, once for its leaves.
        $nodes = array_values(array_unique(ValidatedData::integerList($plan['retire_nodes'])));
        if ($nodes === []) {
            throw new EngineUnsupported('A removal without a serving Node runs on Swamp.');
        }
        $vpns = array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['dns']));
        self::development($nodes, $vpns);
        // The hold the bind takes (holdNodes) covers every Node the flow reads or writes, the DNS-only VPN Nodes too.
        if (array_diff([...$nodes, ...$vpns], RouteWriteNodes::of($operation->input ?? [])) !== []) {
            throw new EngineUnsupported('A Node outside the Route write\'s Nodes runs on Swamp.');
        }
        $steps = $dns = [];
        foreach ($vpns as $index => $vpn) {
            self::digest(self::guard($operation, $vpn), 'records');
            self::digest(self::guard($operation, $vpn), 'catalog_file');
            $unlinks = [];
            foreach (['records', 'catalog'] as $file) {
                $steps[] = new StepSpec("dnsread-{$index}-{$file}", $vpn, 'file.read', (string) $vpn, budgetMs: 60_000, resendMs: 10_000);
                $steps[] = new StepSpec($unlinks[] = "dnsunlink-{$index}-{$file}", $vpn, 'file.ensure', (string) $vpn, ["dnsread-{$index}-{$file}"], budgetMs: 60_000, resendMs: 10_000);
            }
            RouteEngineFlow::fits(self::dnsInput($operation, $index, null));
            $steps[] = $dns[] = new StepSpec("dns-{$index}", $vpn, 'config.apply', (string) $vpn, $unlinks, budgetMs: 120_000, resendMs: 20_000);
        }
        $after = [];
        if ($dns !== []) {
            $steps[] = new StepSpec('grace', 0, 'route.dns_grace', 'gateway', array_map(static fn (StepSpec $step): string => $step->name, $dns), budgetMs: 60_000);
            $after = ['grace'];
        }
        foreach ($nodes as $node) {
            self::poolsKept($operation, $node);
            self::digest(self::guard($operation, $node), 'caddy');
            // Every order must sign within the limit before anything starts.
            RouteEngineFlow::fits(self::withdrawInput($operation, $node, null));
            $steps[] = new StepSpec("caddyread-{$node}", $node, 'file.read', (string) $node, $after, budgetMs: 60_000, resendMs: 10_000);
            $steps[] = new StepSpec("guard-{$node}", 0, 'route.guard', 'gateway', ["caddyread-{$node}"], budgetMs: 30_000);
            $steps[] = new StepSpec("caddyunlink-{$node}", $node, 'file.ensure', (string) $node, ["guard-{$node}"], budgetMs: 60_000, resendMs: 10_000);
            $steps[] = new StepSpec("withdraw-{$node}", $node, 'config.apply', (string) $node, ["caddyunlink-{$node}"], budgetMs: 600_000, resendMs: 30_000);
            $steps[] = new StepSpec("poll-{$node}", $node, 'http.probe', (string) $node, ["withdraw-{$node}"], budgetMs: 60_000, resendMs: 10_000);
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', ["poll-{$node}"], budgetMs: 60_000);
        }

        return $steps;
    }

    /**
     * Refuses the start, in its bind transaction, while another open Route write on either engine writes or holds a Node this
     * removal reads or writes (RouteWriteNodes): the serving Nodes and the VPN Nodes. The engine rewrites their whole files.
     */
    public static function holdNodes(Operation $operation): void
    {
        if ($operation->kind === self::Kind) {
            RouteWriteNodes::refuseEngineBeside($operation, RouteWriteNodes::of($operation->input ?? []));
        }
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $parts = explode('-', $step);
        $index = (int) ($parts[1] ?? 0);

        return match ($parts[0]) {
            'dnsread' => ['path' => $parts[2] === 'records' ? self::Records : self::Catalog],
            'dns' => self::dnsInput($operation, $index, $run),
            'caddyread' => ['path' => self::Caddyfile],
            'dnsunlink', 'caddyunlink' => self::unlink($step, $run),
            'withdraw' => self::withdrawInput($operation, $index, $run),
            'poll' => ['url' => self::Admin, 'budget_ms' => self::AdminBudgetMs,
                'lacks' => array_map(static fn (string $domain): string => '"'.$domain.'"', self::domains($operation))],
            default => throw new LogicException("Step [{$step}] is a Gateway step."),
        };
    }

    /** A removal of a link runs only when the read found a link in the file's place (or another kind, which its input refuses). */
    public function needed(Operation $operation, string $step, EngineRun $run): bool
    {
        $kind = explode('-', $step, 2)[0];

        return ! in_array($kind, ['dnsunlink', 'caddyunlink'], true) || ! in_array(self::read($run, self::readOf($step))['kind'], ['absent', 'file'], true);
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        [$kind] = explode('-', $step, 2);

        return match ($kind) {
            'grace' => self::grace($run),
            'guard' => self::caddyGuard($operation, (int) explode('-', $step, 2)[1], $run),
            'sibling' => $this->sibling($operation, (int) explode('-', $step, 2)[1]),
            default => throw new LogicException("Step [{$step}] runs on a Node."),
        };
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => self::failedStep($failed->name, $failed->error_code), 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /** A failed step as the Swamp chart's: a withdrawal whose pools failed is the chart's pool step. */
    public static function failedStep(string $name, ?string $code): string
    {
        return $code === self::PoolFailed && str_starts_with($name, 'withdraw-') ? 'Converge PHP-FPM pools-'.explode('-', $name, 2)[1] : self::chartStep($name);
    }

    /**
     * Refuses a withdrawal that would leave a PHP version's pool without any site, by the pools the Node serves before and after
     * it: the executor places desired pools only. A version the withdrawal leaves untouched is no reason to refuse.
     */
    private static function poolsKept(Operation $operation, int $node): void
    {
        $guard = self::guard($operation, $node);
        $versions = static fn (string $key): array => array_filter(explode(',', ValidatedData::string($guard[$key] ?? throw new EngineUnsupported('No captured pool membership.'))));
        if (array_diff($versions('php_versions'), $versions('php_versions_after')) !== []) {
            throw new EngineUnsupported('A PHP version whose last pool leaves runs on Swamp.');
        }
    }

    /** A step's name as the Swamp chart's iteration, such as `withdraw-3` → `Withdraw site, remove cert-3`. */
    public static function chartStep(string $name): string
    {
        [$kind] = explode('-', $name, 2);
        $index = explode('-', $name)[1] ?? '0';

        return (self::Labels[$kind] ?? throw new LogicException("Step [{$name}] has no chart label.")).'-'.$index;
    }

    /**
     * The grace ends 31 s after the last DNS receipt the Gateway recorded (`done_ms`, which a redelivered receipt does not move),
     * so a restart waits only what is left. No time without a receipt: the step then runs and fails.
     */
    public function waitUntilMs(Operation $operation, string $step, EngineRun $run): ?int
    {
        $done = self::lastDns($run);

        return $step === 'grace' && $done > 0 ? $done + self::GraceMs : null;
    }

    /** Runs once the engine found the grace over ([waitUntilMs]): it records how long after the DNS receipt that was. */
    private static function grace(EngineRun $run): string
    {
        $done = self::lastDns($run);
        if ($done === 0) {
            throw new RuntimeException('route.dns_grace_failed: no DNS receipt');
        }

        return 'waited '.(Engine::nowMs() - $done).' ms after the DNS receipt';
    }

    private static function lastDns(EngineRun $run): int
    {
        $done = 0;
        foreach ($run->steps as $name => $step) {
            if (str_starts_with($name, 'dns-')) {
                $done = max($done, (int) $step->done_ms);
            }
        }

        return $done;
    }

    /**
     * A TLS handshake with another private Route the Node serves, by its WireGuard address: Caddy still serves the rest.
     * Nothing to probe when the Node serves no other Route.
     */
    private function sibling(Operation $operation, int $node): string
    {
        return $this->probeSibling($node, self::domains($operation));
    }

    /**
     * A TLS handshake with another private Route the Node still serves, by its WireGuard address and the Orbit root alone.
     *
     * @param  list<string>  $withdrawn
     */
    public function probeSibling(int $node, array $withdrawn): string
    {
        $sibling = $this->sites->all()->first(static fn (DevelopmentSite $site): bool => $site->nodeId === $node && ! $site->publicListener
            && ! $site->unavailable && ! in_array($site->domain, $withdrawn, true) && preg_match(RouteEngineFlow::Domain, $site->domain) === 1
            && ! str_starts_with($site->domain, '*.'));
        if ($sibling === null) {
            return "no other Route on Node {$node}";
        }
        $address = Node::query()->whereKey($node)->value('wireguard_ip');

        return $this->create->probe(['address' => is_string($address) && $address !== '' ? $address : throw new RuntimeException("Node {$node} has no WireGuard address."),
            'domain' => $sibling->domain]);
    }

    /**
     * Refuses, before any write, a Caddyfile that holds neither the render the Gateway captured at admission nor the desired one:
     * a change outside this Route (`owned_revision_conflict`). A link in its place passes: `caddyunlink` removes it. A missing
     * Caddyfile or any other kind refuses, as main's withdrawal failed to read it.
     */
    private static function caddyGuard(Operation $operation, int $node, EngineRun $run): string
    {
        $read = self::read($run, "caddyread-{$node}");
        if ($read['kind'] === 'link') {
            return 'a link in the Caddyfile\'s place, removed before the write';
        }
        if ($read['kind'] !== 'file' || ! in_array($read['sha256'], [self::digest(self::guard($operation, $node), 'caddy'), hash('sha256', self::caddy($operation, $node))], true)) {
            throw new EngineStepRefusal('owned_revision_conflict', "The Caddyfile on Node {$node} changed outside this Route.");
        }

        return 'the Caddyfile holds the render it was captured from';
    }

    /**
     * A VPN Node's records and catalog without the Route. Each must still hold the bytes the Gateway rendered from at admission,
     * unless its read already found the desired bytes; the catalog may hold that state as Swamp's withdrawal or the canonical form
     * writes it; a link that `dnsunlink` removed is written without a guard. Before the
     * reads, a null run renders the order only to measure it.
     *
     * @return array<string, mixed>
     */
    private static function dnsInput(Operation $operation, int $index, ?EngineRun $run): array
    {
        $row = RouteExecutionProof::objects(self::plan($operation)['dns'])[$index] ?? throw new LogicException("No DNS row {$index}.");
        $guard = self::guard($operation, ValidatedData::integer($row['node_id']));
        $file = static function (string $path, string $content, string $file, string $admitted) use ($run, $index, $guard): array {
            $read = $run === null ? null : self::read($run, "dnsread-{$index}-{$file}");
            $from = match (true) {
                $read === null => self::digest($guard, $admitted),
                $read['kind'] === 'link' => null,
                $read['kind'] === 'file' && $read['sha256'] === hash('sha256', $content) => $read['sha256'],
                // The admitted catalog in another writer's bytes: Swamp's withdrawal, or the canonical form.
                $read['kind'] === 'file' && $file === 'catalog' && in_array($read['sha256'], array_filter([$guard['catalog_swamp'] ?? null, $guard['catalog'] ?? null]), true) => $read['sha256'],
                default => self::digest($guard, $admitted),
            };

            return ['path' => $path, 'content' => ['blob' => ['content' => $content]], 'from_sha256' => $from, 'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']];
        };

        return ['lock' => self::DnsLock, 'recovery_dir' => self::Recovery."/operation-{$operation->id}/dns-{$index}",
            'files' => [$file(self::Records, ValidatedData::string($row['records']), 'records', 'records'),
                $file(self::Catalog, ValidatedData::string($row['catalog']), 'catalog', 'catalog_file')],
            'validators' => [['kind' => 'dnsmasq', 'conf_file' => '/etc/dnsmasq.conf', 'conf_dir' => '/etc/dnsmasq.d', 'skip_suffixes' => ['.dpkg-dist', '.dpkg-old', '.dpkg-new']]],
            'units' => [['unit' => 'dnsmasq.service', 'load' => 'restart'], ['unit' => 'orbit-private-dns.service', 'load' => 'restart']]];
    }

    /**
     * One Node's Caddyfile without the Route, guarded by the digest its read answered (the guard step checked it against the
     * admission render), or unguarded after a link in its place went; validated by Caddy and reloaded on every run, so a
     * repeat after a crash still makes the withdrawal live.
     *
     * @return array<string, mixed>
     */
    private static function withdrawInput(Operation $operation, int $node, ?EngineRun $run): array
    {
        $read = $run === null ? null : self::read($run, "caddyread-{$node}");

        return ['lock' => self::CaddyLock, 'recovery_dir' => self::Recovery."/operation-{$operation->id}/withdraw-{$node}",
            'files' => [['path' => self::Caddyfile, 'content' => ['blob' => ['content' => self::caddy($operation, $node)]],
                'from_sha256' => $read['sha256'] ?? null, 'owner' => ['user' => 'root', 'group' => 'caddy', 'mode' => '0640']]],
            'validators' => [['kind' => 'caddy', 'config' => self::Caddyfile, 'user' => 'caddy']],
            'units' => [['unit' => 'caddy.service', 'load' => 'reload']]];
    }

    /**
     * The removal of the link a read found in a file's place, never following it. Any other kind but a plain file refuses.
     *
     * @return array<string, mixed>
     */
    private static function unlink(string $step, EngineRun $run): array
    {
        $read = self::read($run, self::readOf($step));
        if ($read['kind'] !== 'link') {
            throw new EngineStepRefusal('owned_revision_conflict', "Step [{$step}] found a {$read['kind']} in the file's place.");
        }
        $parts = explode('-', $step);

        return ['state' => 'absent', 'path' => $parts[0] === 'caddyunlink' ? self::Caddyfile : ($parts[2] === 'records' ? self::Records : self::Catalog)];
    }

    /** The read step an unlink step follows: `dnsunlink-0-records` → `dnsread-0-records`, `caddyunlink-3` → `caddyread-3`. */
    private static function readOf(string $step): string
    {
        return str_replace(['dnsunlink-', 'caddyunlink-'], ['dnsread-', 'caddyread-'], $step);
    }

    /** The Caddyfile the Node serves once the Route is gone, as the Gateway rendered it. */
    private static function caddy(Operation $operation, int $node): string
    {
        $caddy = array_values(array_filter(RouteExecutionProof::objects(ValidatedData::object(self::plan($operation)['retire'])['caddy'] ?? []),
            static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node));

        return $caddy === [] ? throw new EngineUnsupported("Node {$node} has no rendered Caddyfile to withdraw from.") : ValidatedData::string(end($caddy)['content']);
    }

    /**
     * What a `file.read` step found: `absent`, a plain `file` with its digest, a `link`, or another kind by its type.
     *
     * @return array{kind: string, sha256: ?string}
     */
    private static function read(EngineRun $run, string $step): array
    {
        $read = json_decode((string) $run->output($step), true);
        if (! is_array($read) || ! in_array($read['state'] ?? null, ['absent', 'present'], true)) {
            throw new LogicException("Step [{$step}] has no read.");
        }
        if ($read['state'] === 'absent') {
            return ['kind' => 'absent', 'sha256' => null];
        }
        $type = is_string($read['type'] ?? null) ? $read['type'] : 'unknown';
        if ($type === 'file' && ! is_string($read['sha256'] ?? null)) {
            throw new LogicException("Step [{$step}] read a file without its digest.");
        }

        return ['kind' => $type === 'symlink' ? 'link' : $type, 'sha256' => $type === 'file' ? $read['sha256'] : null];
    }

    /**
     * Each Node with work is a development Node: an app-dev Node serves the Route, a VPN Node answers its names. Anything else
     * runs on Swamp (ORB-6337).
     *
     * @param  list<int>  $serving
     * @param  list<int>  $vpns
     */
    private static function development(array $serving, array $vpns): void
    {
        $has = static fn (int $node, RoleName $role): bool => Node::query()->whereKey($node)
            ->whereHas('roles', static fn ($query) => $query->where('role', $role)->where('status', LifecycleStatus::Active))->exists();
        foreach ($serving as $node) {
            if (! $has($node, RoleName::AppDev)) {
                throw new EngineUnsupported("Node {$node} is no development Node.");
            }
        }
        foreach ($vpns as $node) {
            if (! $has($node, RoleName::Vpn)) {
                throw new EngineUnsupported("Node {$node} is no VPN Node.");
            }
        }
    }

    /**
     * The names the Route served. A replacement (it restores the old domain's URL and DNS) runs on Swamp.
     *
     * @return list<string>
     */
    private static function domains(Operation $operation): array
    {
        $routes = RouteExecutionProof::objects(ValidatedData::object($operation->input['route_removal'] ?? [])['routes'] ?? []);
        if (count($routes) !== 1 || ($routes[0]['replacement_of'] ?? null) !== null) {
            throw new EngineUnsupported('A replacement or a multi-Route removal runs on Swamp.');
        }
        $domain = ValidatedData::string($routes[0]['domain']);

        return preg_match(RouteEngineFlow::Domain, $domain) === 1 ? [$domain] : throw new EngineUnsupported('A domain outside the executor\'s shape runs on Swamp.');
    }

    /**
     * The digests of the whole files the Gateway rendered from at admission (NativeRouteRemovalAdmission `route_guards`).
     *
     * @return array<string, mixed>
     */
    public static function guard(Operation $operation, int $node): array
    {
        $guards = is_array($operation->input['route_guards'] ?? null) ? $operation->input['route_guards'] : [];

        return is_array($guards[$node] ?? null) ? ValidatedData::object($guards[$node]) : throw new EngineUnsupported("Node {$node} has no captured file digests.");
    }

    /** @param array<string, mixed> $guard */
    public static function digest(array $guard, string $file): string
    {
        $digest = $guard[$file] ?? null;

        return is_string($digest) && preg_match('/\A[0-9a-f]{64}\z/D', $digest) === 1 ? $digest : throw new EngineUnsupported("No captured {$file} digest.");
    }

    /** @return array<string, mixed> */
    private static function plan(Operation $operation): array
    {
        return ValidatedData::object(ValidatedData::object($operation->input['route_lifecycle'] ?? [])['plan'] ?? []);
    }
}
