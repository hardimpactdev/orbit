<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\ReceiptBoundDefinition;
use App\Domain\Engine\StepSpec;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;
use RuntimeException;

/**
 * `route:destroy` on the state machine (ADR 0271; docs/cli/route/engine/destroy.mmd). It reads the withdrawal plan the
 * Gateway admitted (`route_lifecycle.plan`, NativeRouteRemovalSteps) and the digests of the files it was rendered from:
 *
 * - `dns-<i>`: `dns.publish_records` on each VPN Node, guarded by the digests of the records and catalog it rendered from;
 * - `grace`: the Gateway waits 31 s from the last DNS receipt, so resolvers that honour the TTL stop asking first;
 * - `withdraw-<node>`: `route.withdraw_site` on each Node that served the Route, guarded by its Caddyfile's digest;
 * - `sibling-<node>`: a TLS handshake with another Route on that Node, so Caddy still serves the rest.
 *
 * `--offline`, a replacement, LAN rules, the Ingress edge and any URL write start on Swamp ([EngineUnsupported]). Step
 * labels map onto the Swamp chart's, so a failure records the same `failed_step` and error code.
 */
final readonly class RouteDestroyEngineFlow implements EngineDefinition, ReceiptBoundDefinition
{
    /** The input a VPN Node must take: per-file digests of what the Gateway rendered from. */
    public const string GuardedDns = 'dns.guarded_records';

    /** The executor's code for a pool that failed its test or reload, which the Swamp chart's pool step reports. */
    public const string PoolFailed = 'route.pool_failed';

    /** The DNS grace: the records' TTL of 30 s and one more second. */
    public const int GraceMs = 31_000;

    /** Each step prefix's Swamp chart label (NativeRouteRemovalProjection::StepCodes). */
    private const array Labels = ['dns' => 'Remove private DNS', 'grace' => 'Wait the DNS grace', 'withdraw' => 'Withdraw site, remove cert',
        'sibling' => 'Check nothing is left'];

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
        // A Node can appear twice: once for its sites, once for its leaves.
        $nodes = array_values(array_unique(ValidatedData::integerList($plan['retire_nodes'])));
        $pools = ValidatedData::object($plan['pools']);
        if (array_diff(ValidatedData::integerList($pools['site_nodes']), $nodes) !== [] || $nodes === []) {
            throw new EngineUnsupported('A pool change on a Node the Route does not leave runs on Swamp.');
        }
        $steps = $dns = [];
        foreach (array_keys(RouteExecutionProof::objects($plan['dns'])) as $index) {
            $vpn = ValidatedData::integer(RouteExecutionProof::objects($plan['dns'])[$index]['node_id']);
            RouteEngineFlow::fits(self::dnsInput($operation, $index));
            $steps[] = $dns[] = new StepSpec("dns-{$index}", $vpn, 'dns.publish_records', (string) $vpn, budgetMs: 120_000, resendMs: 20_000, features: [self::GuardedDns]);
        }
        $after = [];
        if ($dns !== []) {
            $steps[] = new StepSpec('grace', 0, 'route.dns_grace', 'gateway', array_map(static fn (StepSpec $step): string => $step->name, $dns), budgetMs: 60_000);
            $after = ['grace'];
        }
        foreach ($nodes as $node) {
            $input = $this->withdrawInput($operation, $node);
            // Every order must sign within the limit before anything starts.
            RouteEngineFlow::fits($input);
            self::poolsKept($operation, $node);
            $steps[] = new StepSpec("withdraw-{$node}", $node, 'route.withdraw_site', (string) $node, $after, budgetMs: 600_000, resendMs: 30_000);
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', ["withdraw-{$node}"], budgetMs: 60_000);
        }

        return $steps;
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        [$kind, $index] = explode('-', $step, 2);

        return match ($kind) {
            'dns' => self::dnsInput($operation, (int) $index),
            'withdraw' => $this->withdrawInput($operation, (int) $index),
            default => throw new LogicException("Step [{$step}] is a Gateway step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        [$kind] = explode('-', $step, 2);

        return match ($kind) {
            'grace' => self::grace($run),
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
        $index = str_contains($name, '-') ? explode('-', $name, 2)[1] : '0';

        return (self::Labels[$kind] ?? throw new LogicException("Step [{$name}] has no chart label.")).'-'.$index;
    }

    /**
     * Waits what is left of the grace after the last DNS receipt the Gateway recorded. A redelivered receipt does not move
     * that time, and a repeat of this step waits only what is left.
     */
    private static function grace(EngineRun $run): string
    {
        $done = 0;
        foreach ($run->steps as $name => $step) {
            if (str_starts_with($name, 'dns-')) {
                $done = max($done, (int) $step->done_ms);
            }
        }
        if ($done === 0) {
            throw new RuntimeException('route.dns_grace_failed: no DNS receipt');
        }
        $left = $done + self::GraceMs - (int) floor(microtime(true) * 1000);
        if ($left > 0) {
            usleep($left * 1000);
        }

        return 'waited '.max(0, $left).' ms';
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
     * A VPN Node's records and catalog without the Route, each with the digest of the bytes the Gateway rendered from.
     *
     * @return array{records: array<string, mixed>, catalog: array<string, mixed>}
     */
    private static function dnsInput(Operation $operation, int $index): array
    {
        $row = RouteExecutionProof::objects(self::plan($operation)['dns'])[$index] ?? throw new LogicException("No DNS row {$index}.");
        $guard = self::guard($operation, ValidatedData::integer($row['node_id']));

        return ['records' => ['blob' => ['content' => ValidatedData::string($row['records'])], 'from_sha256' => self::digest($guard, 'records')],
            'catalog' => ['blob' => ['content' => ValidatedData::string($row['catalog'])], 'from_sha256' => self::digest($guard, 'catalog')]];
    }

    /**
     * One Node's `route.withdraw_site` input: the Caddyfile and pools without the Route, the Route's leaf scopes there, and
     * the digest of the Caddyfile the Gateway rendered from.
     *
     * @return array<string, mixed>
     */
    private function withdrawInput(Operation $operation, int $node): array
    {
        $plan = self::plan($operation);
        $mine = static fn (array $phase, string $list): array => array_values(array_filter(RouteExecutionProof::objects($phase[$list] ?? []),
            static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node));
        $nodes = is_array($plan['nodes'] ?? null) ? $plan['nodes'] : [];
        $account = ValidatedData::object($nodes[$node] ?? [])['account'] ?? null;
        if (! is_string($account) || preg_match(RouteEngineFlow::Name, $account) !== 1) {
            throw new EngineUnsupported("Node {$node} has no account in the executor's shape.");
        }
        $caddy = $mine(ValidatedData::object($plan['retire']), 'caddy');
        $guard = self::guard($operation, $node);
        $from = self::digest($guard, 'caddy');
        if ($caddy === []) {
            throw new EngineUnsupported("Node {$node} has no rendered Caddyfile to withdraw from.");
        }
        $leaves = [];
        foreach (RouteExecutionProof::objects($plan['cleanup']) as $row) {
            $leaf = ValidatedData::object($row['leaf']);
            if (ValidatedData::integer($row['node_id']) !== $node) {
                continue;
            }
            if (ValidatedData::string($leaf['user']) !== $account || preg_match(RouteEngineFlow::Scope, ValidatedData::string($leaf['scope'])) !== 1) {
                throw new EngineUnsupported('A leaf outside the account or scope shape runs on Swamp.');
            }
            $leaves[] = ValidatedData::string($leaf['scope']);
        }
        $pools = ValidatedData::object($plan['pools']);
        $php = RouteEngineFlow::pools($mine($pools, 'php_files'), $mine($pools, 'php_tests'), $mine($pools, 'php_reloads'));

        return ['account' => $account, 'domains' => self::domains($operation), 'leaves' => array_values(array_unique($leaves)),
            'php' => array_map(static fn (array $pool): array => $pool['user'] === null && is_string($guard['php:'.$pool['version']] ?? null)
                ? [...$pool, 'from' => ['blob' => ['content' => $guard['php:'.$pool['version']]]]] : $pool, $php), 'caddy' => ['from_sha256' => $from, 'content' => ['blob' => ['content' => ValidatedData::string(end($caddy)['content'])]]]];
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

    public function terminalStatuses(string $action): array
    {
        return ReceiptBoundDefinition::Terminal[$action] ?? ['done', 'failed'];
    }

    public function terminalFailure(string $action, string $code): bool
    {
        return ! str_starts_with($code, 'blob.');
    }
}
