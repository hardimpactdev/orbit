<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Certificates\SignDirectCertificatesAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\EngineAdmission;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineSkip;
use App\Domain\Engine\EngineStepRefusal;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use LogicException;
use RuntimeException;

/**
 * `route:create` on the state machine (ADR 0271; docs/cli/route/create.mmd). It reads the plan the Gateway admitted
 * (`route_create`, NativeRouteCreatePlan) and runs it as typed steps:
 *
 * - `csr-<i>`: `tls.ensure_csr` on the leaf's Node, which answers a request for a key that never leaves it;
 * - `sign-<i>`: the Gateway signs the request against the admitted leaf, as `operation:direct:certificate-sign` does;
 * - `site-<node>`: `route.publish_site` on each Node with Route work;
 * - `dns-<i>`: `dns.publish_records` on each VPN Node whose private DNS records change;
 * - `probe-<i>`: the Gateway's TLS handshake with each serving Node, by its WireGuard address and the Route's name.
 *
 * A Route that needs the Ingress edge, a LAN rule, a production `.env` or awake markers starts on Swamp
 * ([EngineUnsupported]). Step labels map onto the chart's, so a failure records the same `failed_step` and error code.
 */
final readonly class RouteEngineFlow implements EngineAdmission, EngineDefinition, EngineSkip
{
    /** Each step prefix's chart label (NativeRouteConvergenceProjection::StepCodes). */
    private const array Labels = ['csr' => 'Make a CSR on the Node', 'sign' => 'Sign the CSR', 'site' => 'Install cert, publish site',
        'dns' => 'Publish private DNS', 'probe' => 'Probe the site over TLS'];

    private const array Unsupported = ['lan', 'ingress', 'ingress_ports', 'env_files', 'awake'];

    /**
     * The executor's own shape rules (orbit-executor-route), checked here first so a mismatch starts on Swamp. `Name` is also the
     * owner pattern of the host primitives (`NAME`, crates/host/src/lib.rs): an owner name outside it never reaches an order.
     */
    public const string Name = '/\A[a-z_][a-z0-9_-]{0,31}\z/D';

    public const string Scope = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D';

    private const string Directory = '#\A/etc/caddy/orbit-certificates/[A-Za-z0-9][A-Za-z0-9._-]*/versions/[0-9a-f]{16}\z#D';

    /**
     * The executor input that gives `tls.ensure_csr` its folder's owner and modes and the request's subject (ADR 0271 N3). A Node
     * whose executor does not report it runs the Route on Swamp.
     */
    public const string CsrFeature = 'tls.csr_input';

    public const string Domain = '/\A(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/D';

    /** Earlier outputs as a step's shape check reads them before anything runs: a certificate, and a present file, so each order is sized with a real 64-hex guard. */
    private const array PlannedOutput = ['sign' => "-----BEGIN CERTIFICATE-----\n",
        'site' => '{"state":"present","type":"file","sha256":"0000000000000000000000000000000000000000000000000000000000000000","uid":0,"gid":0,"mode":"0644"}'];

    /** Bytes a signed order adds around its input: the payload's other fields, base64 and the signature. */
    private const int OrderOverhead = 400;

    /** An account answer as the shape check reads it: the longest names, so each order is sized as at run time. */
    private const string PlannedAccount = '{"state":"present","user":"abcdefghijklmnopqrstuvwxyzabcdef","uid":4294967294,"gid":4294967294,"group":"abcdefghijklmnopqrstuvwxyzabcdef","home":"/home/abcdefghijklmnopqrstuvwxyzabcdef"}';

    /** The host primitives route.create sends: a Node reports the `host` family (agent 0.36.0) when it lists each. */
    private const array HostFamily = ['account.read', 'config.apply', 'file.read', 'file.ensure', 'dotenv.ensure', 'systemd.unit.ensure', 'ca.trust.ensure'];

    public function __construct(private NativeRouteConvergenceProjection $projection, private SignDirectCertificatesAction $signer,
        private LeafCertificateSigner $root, private MachineBus $bus) {}

    public function steps(Operation $operation): array
    {
        $plan = self::plan($operation);
        foreach (self::Unsupported as $list) {
            if (($plan[$list] ?? []) !== []) {
                throw new EngineUnsupported("route.create with {$list} runs on Swamp.");
            }
        }
        $steps = $signs = [];
        // Every account the Route writes for is read first, on each site Node: an account NSS does not resolve now refuses at the
        // first request, before any step has changed anything.
        $checks = [];
        foreach (ValidatedData::integerList($plan['site_nodes']) as $node) {
            $reads = [];
            foreach (array_keys(self::users($plan, $node)) as $index) {
                $steps[] = new StepSpec($reads[] = "site-{$node}-account-{$index}", $node, 'account.read', (string) $node, budgetMs: 30_000, resendMs: 10_000);
            }
            // The Gateway checks the answers as the Node's site step: an absent account fails it with main's code, `route.publish_failed`.
            $steps[] = new StepSpec($checks[] = "site-{$node}-accounts", 0, 'account.check', 'gateway', $reads, budgetMs: 10_000);
            if (in_array($node, ValidatedData::integerList($plan['trust']), true)) {
                // The trust anchor must already hold this root, or be absent: a rotation refuses here, before any request.
                $steps[] = new StepSpec("site-{$node}-read-trust", $node, 'file.read', (string) $node, budgetMs: 30_000, resendMs: 10_000);
                $steps[] = new StepSpec($checks[] = "site-{$node}-trustcheck", 0, 'trust.check', 'gateway', ["site-{$node}-read-trust"], budgetMs: 10_000);
            }
        }
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            $node = ValidatedData::integer($leaf['node_id']);
            self::csrInput($leaf);
            $steps[] = new StepSpec("csr-{$index}", $node, 'tls.ensure_csr', (string) $node, $checks, budgetMs: 60_000, resendMs: 15_000, features: [self::CsrFeature]);
            $steps[] = $signs[] = new StepSpec("sign-{$index}", 0, 'certificate.sign', 'gateway', ["csr-{$index}"], budgetMs: 30_000);
        }
        $signed = array_map(static fn (StepSpec $step): string => $step->name, $signs);
        $last = [];
        foreach (ValidatedData::integerList($plan['site_nodes']) as $node) {
            // One Node's part in route.publish_site's order, each step after the one before it: Caddy access, APP_URL, links,
            // pools (written and tested, units enabled and running, then reloaded), leaves and the account's copies, trust, Caddyfile.
            $after = $signed;
            foreach ($this->site($plan, $node) as [$part, $action, $features, $budget]) {
                $name = "site-{$node}-{$part}";
                // Shapes the executor cannot take, or an order over its size, send the Route to Swamp now, before anything starts.
                self::fits($this->hostInput($plan, $node, $part, static fn (string $step): string => str_contains($step, '-account-')
                    ? self::PlannedAccount : self::PlannedOutput[explode('-', $step, 2)[0]]));
                $steps[] = new StepSpec($name, $node, $action, (string) $node, $after, budgetMs: $budget, resendMs: 15_000, features: $features);
                $after = [$name];
            }
            $last = [...$last, ...array_diff($after, $signed)];
        }
        // Private DNS follows the signatures, beside the sites, as the chart orders it. A probe reaches the Node by its
        // WireGuard address, so it waits for the sites alone.
        foreach (array_keys(RouteExecutionProof::objects($plan['dns'] ?? [])) as $index) {
            $vpn = ValidatedData::integer(RouteExecutionProof::objects($plan['dns'])[$index]['node_id']);
            // Each file's live digest, read under the hold, is the guard its write sends.
            $steps[] = new StepSpec("dns-{$index}-read-records", $vpn, 'file.read', (string) $vpn, $signed, budgetMs: 30_000, resendMs: 10_000);
            $steps[] = new StepSpec("dns-{$index}-read-catalog", $vpn, 'file.read', (string) $vpn, ["dns-{$index}-read-records"], budgetMs: 30_000, resendMs: 10_000);
            // A link in a file's place is removed first, never followed (EngineSkip runs it only for a link the read found).
            $steps[] = new StepSpec("dns-{$index}-unlink-records", $vpn, 'file.ensure', (string) $vpn, ["dns-{$index}-read-catalog"], budgetMs: 30_000, resendMs: 10_000);
            $steps[] = new StepSpec("dns-{$index}-unlink-catalog", $vpn, 'file.ensure', (string) $vpn, ["dns-{$index}-unlink-records"], budgetMs: 30_000, resendMs: 10_000);
            self::fits(self::dnsInput(RouteExecutionProof::objects($plan['dns'])[$index], self::PlannedOutput['site'], self::PlannedOutput['site']));
            $steps[] = new StepSpec("dns-{$index}", $vpn, 'config.apply', (string) $vpn, ["dns-{$index}-unlink-catalog"], budgetMs: 120_000, resendMs: 20_000);
        }
        foreach (array_keys(RouteExecutionProof::objects($plan['probes'])) as $index) {
            $steps[] = new StepSpec("probe-{$index}", 0, 'route.probe', 'gateway', $last, budgetMs: 60_000);
        }

        return $steps;
    }

    /** An unlink step runs only when its file's read found a link (RouteHostSteps::linked). */
    public function skips(Operation $operation, string $step, EngineRun $run): bool
    {
        return str_contains($step, '-unlink-') && ! RouteHostSteps::linked($run->output(str_replace('-unlink-', '-read-', $step)));
    }

    /**
     * route.create runs on the engine only for a Route without Caddy access rows, whose every Node with work reports the `host`
     * family (each host primitive the flow sends), and whose every leaf Node also reports `tls.csr_input`. The `host` family comes
     * with agent 0.36.0 (NodeExecutorInput::FamilySince), which every build after file.ensure `source_path` carries, so it also
     * stands for the key copy; that input has no feature of its own. Any other route.create runs on Swamp, as a whole. Caddy
     * access waits on posix.acl.ensure with `scope: tree` (A4, RouteHostSteps::access), so a Route with access rows
     * never reaches it on the engine.
     */
    public function admit(Operation $operation): void
    {
        $plan = self::plan($operation);
        // Production never moves (as ORB-6337's Process gate): a production placement or release keeps the whole Operation on Swamp.
        if (isset($operation->input['production_control']) || self::production($operation->input['route_lifecycle'] ?? null)) {
            throw new EngineUnsupported('A Route with a production placement stays on Swamp.');
        }
        foreach (Route::query()->find(ValidatedData::integer($plan['route_id']))?->targets()->with('instance')->get() ?? [] as $target) {
            if (! $target->instance->placedOnAppDev()) {
                throw new EngineUnsupported('A Route to an Instance outside development stays on Swamp.');
            }
        }
        if (($plan['acl'] ?? []) !== []) {
            throw new EngineUnsupported('A Route with Caddy access rows runs on Swamp until posix.acl.ensure takes scope tree (A4).');
        }
        $leaves = array_map(static fn (array $leaf): int => ValidatedData::integer($leaf['node_id']), RouteExecutionProof::objects($plan['leaves']));
        $nodes = array_values(array_unique([...ValidatedData::integerList($plan['site_nodes']), ...$leaves,
            ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['dns'] ?? []))]));
        $vpn = array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['dns'] ?? []));
        $sites = array_values(array_unique([...ValidatedData::integerList($plan['site_nodes']), ...$leaves]));
        foreach ($nodes as $node) {
            $host = Node::query()->find($node);
            // A Node with site work is an app-dev Node; a Node with DNS work alone is a VPN Node. Any other Node stays on Swamp.
            $allowed = in_array($node, $sites, true)
                ? $host?->placementEnvironment() === 'development' && $host->roles->contains('role', RoleName::AppDev)
                : in_array($node, $vpn, true) && $host?->roles->contains('role', RoleName::Vpn) === true;
            if (! $allowed) {
                throw new EngineUnsupported("Node {$node} is not an app-dev Node; the Route runs on Swamp.");
            }
            if (array_diff(self::HostFamily, $this->bus->nodeActions($node)) !== []) {
                throw new EngineUnsupported("Node {$node} lacks the host family; the Route runs on Swamp.");
            }
            if (in_array($node, $leaves, true) && ! in_array(self::CsrFeature, $this->bus->nodeFeatures($node), true)) {
                throw new EngineUnsupported("Node {$node} lacks tls.csr_input; the Route runs on Swamp.");
            }
        }
    }

    /**
     * One Node's steps as `[part, action, features, budget]`, in route.publish_site's order.
     *
     * @param  array<string, mixed>  $plan
     * @return list<array{string, string, list<string>, int}>
     */
    private function site(array $plan, int $node): array
    {
        $mine = self::mine($plan, $node);
        $parts = [];
        foreach (array_keys(RouteHostSteps::access(array_map(self::access(...), $mine('acl')))) as $index) {
            $parts[] = ["acl-{$index}", 'posix.acl.ensure', [], 150_000];
        }
        foreach (array_keys($mine('url_lines')) as $index) {
            $parts[] = ["url-{$index}", 'dotenv.ensure', [], 60_000];
        }
        foreach (array_keys($mine('links')) as $index) {
            $parts[] = ["link-{$index}", 'file.ensure', [], 60_000];
        }
        $pools = self::pools($mine('php_files'), $mine('php_tests'), $mine('php_reloads'));
        if ($pools !== []) {
            foreach (array_keys($pools) as $index) {
                $parts[] = ["read-pool-{$index}", 'file.read', [], 30_000];
                $parts[] = ["unlink-pool-{$index}", 'file.ensure', [], 30_000];
            }
            $parts[] = ['pools', 'config.apply', [], 180_000];
            foreach (array_keys($pools) as $index) {
                $parts[] = ["unit-{$index}", 'systemd.unit.ensure', [], 60_000];
            }
            $parts[] = ['reload', 'config.apply', [], 180_000];
        }
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            if (ValidatedData::integer($leaf['node_id']) === $node) {
                $parts = [...$parts, ["cert-{$index}", 'file.ensure', [], 60_000], ["current-{$index}", 'file.ensure', [], 60_000],
                    ["folder-{$index}", 'file.ensure', [], 60_000], ["key-{$index}", 'file.ensure', [], 60_000],
                    ["copy-{$index}", 'file.ensure', [], 60_000], ["copylink-{$index}", 'file.ensure', [], 60_000]];
            }
        }
        if (in_array($node, ValidatedData::integerList($plan['trust']), true)) {
            $parts[] = ['trust', 'ca.trust.ensure', [], 120_000];
        }
        if ($mine('caddy') !== []) {
            $parts[] = ['read-caddy', 'file.read', [], 30_000];
            $parts[] = ['unlink-caddy', 'file.ensure', [], 30_000];
            $parts[] = ['caddy', 'config.apply', [], 180_000];
        }

        return $parts;
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $plan = self::plan($operation);
        $parts = explode('-', $step, 3);

        return match ($parts[0]) {
            'csr' => self::csrInput(RouteExecutionProof::objects($plan['leaves'])[(int) $parts[1]]),
            'site' => $this->hostInput($plan, (int) $parts[1], $parts[2] ?? '', $run->output(...)),
            'dns' => match ($parts[2] ?? null) {
                'read-records' => RouteHostSteps::read(RouteHostSteps::Records),
                'read-catalog' => RouteHostSteps::read(RouteHostSteps::Catalog),
                'unlink-records' => RouteHostSteps::absent(RouteHostSteps::Records),
                'unlink-catalog' => RouteHostSteps::absent(RouteHostSteps::Catalog),
                null => self::dnsInput(RouteExecutionProof::objects($plan['dns'])[(int) $parts[1]], $run->output("{$step}-read-records"), $run->output("{$step}-read-catalog")),
                default => throw new LogicException("Step [{$step}] has no input."),
            },
            default => throw new LogicException("Step [{$step}] is a Gateway step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        $plan = self::plan($operation);
        [$kind, $index] = explode('-', $step, 2);

        return match ($kind) {
            'sign' => $this->sign($operation, RouteExecutionProof::objects($plan['leaves'])[(int) $index], $run->output("csr-{$index}") ?? '', $run),
            'probe' => $this->probe(RouteExecutionProof::objects($plan['probes'])[(int) $index]),
            'site' => str_ends_with($index, '-trustcheck') ? self::trustCheck($plan, (int) $index, $run) : self::accounts($plan, (int) $index, $run),
            default => throw new LogicException("Step [{$step}] runs on a Node."),
        };
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        // The Gateway's signer refuses once a cancel is pending: that refusal is the cancel, not a failed signature.
        if ($failed !== null && $operation->cancel_requested_at !== null && str_starts_with($failed->name, 'sign-')) {
            $failed = null;
        }
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => self::chartStep($failed->name), 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /** A step's name as the chart's iteration, such as `site-3` → `Install cert, publish site-3`, for NativeFailedStep. */
    public static function chartStep(string $name): string
    {
        // A Node's site steps are one chart iteration, `Install cert, publish site-<node>`, as route.publish_site was.
        [$kind, $index] = explode('-', $name, 3) + [1 => ''];

        return (self::Labels[$kind] ?? throw new LogicException("Step [{$name}] has no chart label.")).'-'.$index;
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @return array{directory: string, key: string, user: string, group: string, directory_mode: string, key_mode: string, subject: string, domain: string}
     */
    public static function csrInput(array $leaf): array
    {
        $directory = ValidatedData::string($leaf['directory']);
        $domain = ValidatedData::string($leaf['domain']);
        if (preg_match(self::Directory, $directory) !== 1 || preg_match(self::Domain, $domain) !== 1) {
            throw new EngineUnsupported('A leaf outside the executor\'s shape runs on Swamp.');
        }

        // The version folder is root:caddy 0750 and its key root:caddy 0640, so only Caddy reads it; the request's CN is `Orbit`.
        return ['directory' => $directory, 'key' => $directory.'/key.pem', 'user' => 'root', 'group' => 'caddy', 'directory_mode' => '0750', 'key_mode' => '0640',
            'subject' => '/CN=Orbit', 'domain' => $domain];
    }

    /**
     * A VPN Node's records and catalog, as the Gateway rendered them at admission, each guarded by its admission digest.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function dnsInput(array $row, ?string $records, ?string $catalog): array
    {
        // The guard is the admission digest, as route.update and route.destroy guard theirs: a payload that went stale while the
        // create waited for its hold refuses without writing. config.apply still keeps a file that already holds the payload. A
        // link the read found was removed first, so that file takes no guard.
        $from = is_array($row['from'] ?? null) ? $row['from'] : [];
        $guard = static fn (string $file, ?string $read): ?string => RouteHostSteps::linked($read) || ! is_string($from[$file] ?? null) ? null : $from[$file];

        return RouteHostSteps::dns(ValidatedData::string($row['records']), ValidatedData::string($row['catalog']), $guard('records', $records), $guard('catalog', $catalog));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return callable(string): list<array<string, mixed>>
     */
    private static function mine(array $plan, int $node): callable
    {
        return static fn (string $list): array => array_values(array_filter(RouteExecutionProof::objects($plan[$list] ?? []),
            static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node));
    }

    /** Whether a lifecycle intent names a production workload anywhere (`production: true`). */
    private static function production(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (($value['production'] ?? null) === true) {
            return true;
        }
        foreach ($value as $child) {
            if (self::production($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The accounts a Node's steps write for: the Node's account (the leaf copies and the pools' group and home) first, then each
     * other owner of a link, in order.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private static function users(array $plan, int $node): array
    {
        $nodes = is_array($plan['nodes'] ?? null) ? $plan['nodes'] : [];
        $user = is_array($nodes[$node] ?? null) ? ($nodes[$node]['account'] ?? null) : null;
        if (! is_string($user)) {
            throw new EngineUnsupported("Node {$node} has no account.");
        }
        $users = [$user];
        foreach (self::mine($plan, $node)('links') as $link) {
            $users[] = ValidatedData::string($link['owner']);
        }
        $users = array_values(array_unique($users));
        // Admission gate: every owner name the Gateway records (the Route's account, each other link owner) must fit the host
        // primitives' owner pattern, or the whole Route runs on Swamp before any step, such as `Orbit` or `Dev.Team`.
        foreach ($users as $name) {
            if (preg_match(self::Name, $name) !== 1) {
                throw new EngineUnsupported('An owner name outside the host primitives\' pattern runs on Swamp.');
            }
        }

        return $users;
    }

    /**
     * Checks that every account a Node's steps write for answered a resolvable account. An absent one refuses here, before the
     * first request, so nothing has changed; the step is the Node's site step, so the Route records `route.publish_failed`.
     *
     * @param  array<string, mixed>  $plan
     */
    private static function accounts(array $plan, int $node, EngineRun $run): string
    {
        foreach (self::users($plan, $node) as $index => $user) {
            try {
                [$group, , , $canonical] = self::owner($run->output("site-{$node}-account-{$index}"));
            } catch (RuntimeException $failure) {
                throw new EngineStepRefusal($failure->getMessage(), "Account {$user} on Node {$node} is not resolvable.", $failure);
            }
            // The names NSS answered go to `file.ensure` as owners: one outside its pattern refuses here, before any request.
            if (preg_match(self::Name, $canonical) !== 1 || preg_match(self::Name, $group) !== 1) {
                throw new EngineStepRefusal('route.account_invalid', "Account {$user} on Node {$node} resolves to a name the host primitives refuse.");
            }
        }

        return "accounts resolved on node {$node}";
    }

    /**
     * Checks that the trust anchor already holds the Route's root, or is absent. An Orbit root rotation is not run here: a
     * same-name replacement leaves the new root without its subject-hash link, which `ca.trust.ensure` refuses. The create
     * refuses before any request, as the Node's site step (`route.publish_failed`); during a rotation route.create is off in
     * `ORBIT_ENGINE_KINDS` until Swamp has rotated every development Node.
     *
     * @param  array<string, mixed>  $plan
     */
    private static function trustCheck(array $plan, int $node, EngineRun $run): string
    {
        $read = $run->output("site-{$node}-read-trust");
        $answer = is_string($read) ? json_decode($read, true) : null;
        if (is_array($answer) && ($answer['state'] ?? null) === 'absent') {
            return "no trust anchor on node {$node} yet";
        }
        if (RouteHostSteps::digest($read) !== hash('sha256', ValidatedData::string($plan['root_certificate']))) {
            throw new EngineStepRefusal('route.trust_rotation', "Node {$node} trusts another Orbit root: rotate it on Swamp first.");
        }

        return "node {$node} trusts the root";
    }

    /**
     * An account as its recorded `account.read` answered it: `[group, home, gid, user]`, the primary group by NSS on the Node and
     * the canonical name NSS answered, which owns the account's files. `absent` means NSS does not resolve it now
     * (RouteEngineFlow::accounts refuses before anything changed).
     *
     * @return array{string, string, int, string}
     */
    public static function owner(?string $read): array
    {
        $answer = is_string($read) ? json_decode($read, true) : null;
        if (! is_array($answer) || ($answer['state'] ?? null) === 'absent') {
            throw new RuntimeException('route.account_missing');
        }
        $group = $answer['group'] ?? null;
        $home = $answer['home'] ?? null;
        $gid = $answer['gid'] ?? null;
        $user = $answer['user'] ?? null;
        // Any account NSS resolves is usable: its names are taken as NSS answers them, whatever their case or characters.
        $named = static fn (mixed $name): bool => is_string($name) && $name !== '' && strpbrk($name, ":\n\0") === false;
        if (($answer['state'] ?? null) !== 'present' || ! is_string($user) || ! $named($user) || ! is_string($group) || ! $named($group) || ! is_string($home) || ! str_starts_with($home, '/')
            || strpbrk($home, "\n\0") !== false || ! is_int($gid)) {
            throw new RuntimeException('route.account_invalid');
        }

        // One spelling of the home: repeated slashes joined, no trailing slash, `/` for the root.
        return [$group, '/'.trim((string) preg_replace('#/+#', '/', $home), '/'), $gid, $user];
    }

    /**
     * One site step's primitive input. `$output` gives an earlier step's recorded output: a leaf's signed certificate
     * (`sign-<i>`) or a file's read (`site-<node>-read-…`); content travels as blobs.
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(string): ?string  $output
     * @return array<string, mixed>
     */
    private function hostInput(array $plan, int $node, string $part, callable $output): array
    {
        $signed = static fn (int $leaf): string => $output("sign-{$leaf}") ?? throw new LogicException("Leaf {$leaf} has no signed certificate.");
        $read = static fn (string $file): ?string => RouteHostSteps::digest($output("site-{$node}-read-{$file}"));
        $mine = self::mine($plan, $node);
        $users = self::users($plan, $node);
        $account = $users[0];
        $who = static fn (string $user): array => self::owner($output("site-{$node}-account-".array_search($user, $users, true)));
        [$kind, $index] = explode('-', $part, 2) + [1 => '0'];
        $index = (int) $index;
        $caddy = $mine('caddy');
        $named = static fn (string $name): string => preg_match(self::Name, $name) === 1 ? $name : throw new EngineUnsupported('An account outside the executor\'s shape runs on Swamp.');
        $pools = static fn (): array => RouteHostSteps::pools(self::pools($mine('php_files'), $mine('php_tests'), $mine('php_reloads')), $who($account)[0], $who($account)[1],
            static fn (int $index): ?string => $read("pool-{$index}"));
        $copies = static fn (): string => rtrim($who($account)[1], '/').'/.orbit/certificates/';
        $leaf = static function () use ($plan, $index, $account): array {
            $leaf = RouteExecutionProof::objects($plan['leaves'])[$index];
            $scope = ValidatedData::string($leaf['scope']);
            if (ValidatedData::string($leaf['user']) !== $account || preg_match('#\Aversions/([0-9a-f]{16})\z#', ValidatedData::string($leaf['target']), $version) !== 1
                || preg_match(self::Scope, $scope) !== 1) {
                throw new EngineUnsupported('A leaf outside the account or version shape runs on Swamp.');
            }

            return [$scope, ValidatedData::string($leaf['target']), ValidatedData::string($leaf['directory']), ValidatedData::string($leaf['current'])];
        };

        return match ($kind) {
            'url' => (static function (array $row) use ($named): array {
                $path = ValidatedData::string($row['path']);
                $line = ValidatedData::string($row['line']);
                if (! str_ends_with($path, '/.env') || ! str_starts_with($line, 'APP_URL=')) {
                    throw new EngineUnsupported('A URL line outside APP_URL in a .env runs on Swamp.');
                }

                return ['path' => $path, 'user' => $named(ValidatedData::string($row['owner'])), 'values' => [['key' => 'APP_URL', 'value' => substr($line, 8)]]];
            })($mine('url_lines')[$index]),
            'account' => ['user' => $users[$index]],
            // A link belongs to its owner and the owner's primary group, as its account read answered them.
            'link' => RouteHostSteps::link(ValidatedData::string($mine('links')[$index]['path']), ValidatedData::string($mine('links')[$index]['target']),
                $who(ValidatedData::string($mine('links')[$index]['owner']))[3], $who(ValidatedData::string($mine('links')[$index]['owner']))[0]),
            'pools' => RouteHostSteps::apply(RouteHostSteps::CaddyLock, 'pool-install', $pools()['files'], $pools()['validators']),
            'unit' => RouteHostSteps::running($pools()['units'][$index]),
            // The reload writes nothing: each file's guard is the bytes the install left, so a change since then refuses.
            'reload' => RouteHostSteps::apply(RouteHostSteps::CaddyLock, 'pool-reload', array_map(static fn (array $file): array => [...$file,
                'from_sha256' => hash('sha256', ValidatedData::string(ValidatedData::object(ValidatedData::object($file['content'])['blob'])['content']))], $pools()['files']), $pools()['validators'],
                array_map(static fn (string $unit): array => ['unit' => $unit, 'load' => 'reload'], $pools()['units'])),
            'cert' => RouteHostSteps::plain($leaf()[2].'/cert.pem', self::leafPem($signed($index)), 'root', 'caddy', '0644'),
            'current' => RouteHostSteps::link($leaf()[3], $leaf()[1], 'root', 'root'),
            // `install -D` made the account copy's folders as root, 0755.
            'folder' => RouteHostSteps::directory($copies().$leaf()[0].'/'.$leaf()[1], 'root', 'root', '0755'),
            'key' => RouteHostSteps::copy($copies().$leaf()[0].'/'.$leaf()[1].'/key.pem', $leaf()[2].'/key.pem', $who($account)[3], $who($account)[0], '0600'),
            'copy' => RouteHostSteps::plain($copies().$leaf()[0].'/'.$leaf()[1].'/cert.pem', self::leafPem($signed($index)), $who($account)[3], $who($account)[0], '0600'),
            'copylink' => RouteHostSteps::link($copies().$leaf()[0].'/current', $leaf()[1], $who($account)[3], $who($account)[0]),
            'trust' => ['state' => 'present', 'name' => RouteHostSteps::TrustName, 'certificate' => ['blob' => ['content' => ValidatedData::string($plan['root_certificate'])]],
                'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
            'unlink' => RouteHostSteps::absent(ValidatedData::string($this->hostInput($plan, $node, 'read-'.substr($part, strlen('unlink-')), $output)['path'])),
            // A read names the file its write replaces: the Caddyfile, or the pool at an index.
            'read' => RouteHostSteps::read($part === 'read-caddy' ? RouteHostSteps::Caddyfile : ($part === 'read-trust' ? RouteHostSteps::TrustFile : ValidatedData::string(RouteHostSteps::pools(
                self::pools($mine('php_files'), $mine('php_tests'), $mine('php_reloads')), '', '', static fn (): ?string => null)['files'][(int) substr($part, strlen('read-pool-'))]['path'] ?? null))),
            'caddy' => RouteHostSteps::caddy(ValidatedData::string(end($caddy)['content'] ?? null), $read('caddy')),
            default => throw new LogicException("Site step [{$part}] has no input."),
        };
    }

    /**
     * Refuses a site order that would pass the signer's limit once its blobs are digests (ExecutorOrderSigner::MaxWireBytes).
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function fits(array $input): void
    {
        $digests = static function (array $value) use (&$digests): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = is_array($item['blob'] ?? null) ? [...$item, 'blob' => ['sha256' => str_repeat('0', 64)]] : $digests($item);
                }
            }

            return $value;
        };
        $json = json_encode($digests($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ((int) ceil((strlen($json) + self::OrderOverhead) / 3) * 4 > ExecutorOrderSigner::MaxWireBytes) {
            throw new EngineUnsupported('A site order over the signer\'s limit runs on Swamp.');
        }
    }

    /** @return array<string, mixed> */
    private static function plan(Operation $operation): array
    {
        return ValidatedData::object($operation->input['route_create'] ?? []);
    }

    /**
     * One Node's `route.publish_site` input. `$signed` gives a leaf's signed certificate by its index; content travels as
     * blobs. Each access row must be one of the shapes RouteWebRootCommands renders, and each pool one of the two pool kinds.
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(int): string  $signed
     * @param  list<int>|null  $indexes  The leaves this publication installs; null for every leaf of the plan.
     * @return array<string, mixed>
     */
    public function siteInput(array $plan, int $node, callable $signed, ?array $indexes = null): array
    {
        $mine = static fn (string $list): array => array_values(array_filter(RouteExecutionProof::objects($plan[$list] ?? []),
            static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node));
        // JSON keys such as "3" are integer keys in a PHP array.
        $nodes = is_array($plan['nodes'] ?? null) ? $plan['nodes'] : [];
        $connection = $nodes[$node] ?? null;
        if (! is_array($connection) || ! is_string($connection['account'] ?? null)) {
            throw new EngineUnsupported("Node {$node} has no connection.");
        }
        $account = $connection['account'];
        $named = static fn (string $name): string => preg_match(self::Name, $name) === 1 ? $name : throw new EngineUnsupported('An account outside the executor\'s shape runs on Swamp.');
        $named($account);
        $leaves = [];
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            if (ValidatedData::integer($leaf['node_id']) !== $node || ($indexes !== null && ! in_array($index, $indexes, true))) {
                continue;
            }
            if (ValidatedData::string($leaf['user']) !== $account || preg_match('#\Aversions/([0-9a-f]{16})\z#', ValidatedData::string($leaf['target']), $version) !== 1
                || preg_match(self::Scope, ValidatedData::string($leaf['scope'])) !== 1) {
                throw new EngineUnsupported('A leaf outside the account or version shape runs on Swamp.');
            }
            $leaves[] = ['scope' => ValidatedData::string($leaf['scope']), 'version' => $version[1], 'certificate' => ['blob' => ['content' => self::leafPem($signed($index))]]];
        }
        $caddy = $mine('caddy');
        $trusted = in_array($node, ValidatedData::integerList($plan['trust']), true);

        return [
            'account' => $account,
            'acl' => array_map(self::access(...), $mine('acl')),
            'url_lines' => array_map(static fn (array $row): array => ['path' => str_ends_with(ValidatedData::string($row['path']), '/.env') ? ValidatedData::string($row['path'])
                : throw new EngineUnsupported('A URL line outside a .env runs on Swamp.'),
                'url' => str_starts_with(ValidatedData::string($row['line']), 'APP_URL=') ? substr(ValidatedData::string($row['line']), 8)
                    : throw new EngineUnsupported('A URL line outside APP_URL runs on Swamp.'),
                'owner' => $named(ValidatedData::string($row['owner']))], $mine('url_lines')),
            'links' => array_map(static fn (array $row): array => ['path' => ValidatedData::string($row['path']), 'target' => ValidatedData::string($row['target']),
                'owner' => $named(ValidatedData::string($row['owner']))], $mine('links')),
            'php' => self::pools($mine('php_files'), $mine('php_tests'), $mine('php_reloads')),
            'leaves' => $leaves,
            'root_certificate' => $trusted ? ['blob' => ['content' => ValidatedData::string($plan['root_certificate'])]] : null,
            'caddy' => $caddy === [] ? null : ['blob' => ['content' => ValidatedData::string(end($caddy)['content'])]],
        ];
    }

    /**
     * An access row as its typed kind. The kind's command, rebuilt as the executor builds it, must equal the admitted one.
     *
     * @param  array<string, mixed>  $row
     * @return array{kind: string, user: ?string, paths: list<string>, optional_paths: list<string>}
     */
    private static function access(array $row): array
    {
        $argv = ValidatedData::stringList($row['argv']);
        $user = null;
        if (array_slice($argv, 0, 2) === ['/usr/sbin/runuser', '-u'] && array_slice($argv, 3, 4) === ['--', '/usr/bin/env', '-C', '/']) {
            $user = $argv[2];
            $argv = array_slice($argv, 7);
        }
        $paths = ($argv[0] ?? '') === '/usr/bin/find' ? array_slice($argv, 2, -9) : array_slice($argv, (int) array_search('--', $argv, true) + 1);
        foreach (['deny_defaults', 'deny', 'passage', 'grant_defaults', 'grant'] as $kind) {
            if (self::accessArgv($kind, $paths) === $argv && ($kind === 'passage') === ($user === null)) {
                return ['kind' => $kind, 'user' => $user, 'paths' => $paths, 'optional_paths' => ValidatedData::stringList($row['optional_paths'] ?? [])];
            }
        }
        throw new EngineUnsupported('An access command outside the known shapes runs on Swamp.');
    }

    /**
     * The executor's command for an access kind, after the `runuser` prefix (orbit-executor-route `access_argv`).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function accessArgv(string $kind, array $paths): array
    {
        return match ($kind) {
            'deny_defaults' => ['/usr/bin/find', '-P', ...$paths, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:---', '--', '{}', '+'],
            'grant_defaults' => ['/usr/bin/find', '-P', ...$paths, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:r-x', '--', '{}', '+'],
            'deny' => ['/usr/bin/setfacl', '-P', '-R', '-n', '-m', 'u:caddy:---', '--', ...$paths],
            'grant' => ['/usr/bin/setfacl', '-P', '-R', '-n', '-m', 'u:caddy:r-X', '--', ...$paths],
            'passage' => ['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', ...$paths],
            default => throw new LogicException("Unknown access kind [{$kind}]."),
        };
    }

    /**
     * Pools as `{version, user}`: the executor derives each path, test and unit, which must equal the admitted ones.
     *
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $tests
     * @param  list<array<string, mixed>>  $reloads
     * @return list<array{version: string, user: ?string, content: array{blob: array{content: string}}}>
     */
    public static function pools(array $files, array $tests, array $reloads): array
    {
        if (count($files) !== count($tests) || count($files) !== count($reloads)) {
            throw new EngineUnsupported('PHP-FPM pools without one test and one unit each run on Swamp.');
        }
        $pools = $expected = [];
        foreach ($files as $index => $file) {
            $unit = ValidatedData::string(($reloads[$index] ?? [])['unit'] ?? '');
            [$version, $user] = match (true) {
                preg_match('#\A/etc/php/(\d\.\d{1,2})/fpm/pool\.d/orbit-scopes\.conf\z#', ValidatedData::string($file['path']), $match) === 1 => [$match[1], null],
                preg_match('#\Aorbit-([a-z_][a-z0-9_-]*)-php(\d\.\d{1,2})-fpm\.service\z#', $unit, $match) === 1 => [$match[2], $match[1]],
                default => throw new EngineUnsupported('A PHP-FPM pool outside the two pool kinds runs on Swamp.'),
            };
            $expected[] = [$user === null ? "/etc/php/{$version}/fpm/pool.d/orbit-scopes.conf" : "/etc/orbit/php-fpm/{$user}/generated/pool.conf",
                $user === null ? ['/usr/sbin/php-fpm'.$version, '-t'] : ['/usr/bin/env', "PHP_INI_SCAN_DIR=/etc/php/{$version}/fpm/conf.d:/etc/orbit/php-fpm/{$user}/generated",
                    '/usr/sbin/php-fpm'.$version, '-t', '--fpm-config', "/etc/orbit/php-fpm/{$user}/generated/php-fpm.conf"],
                $user === null ? "php{$version}-fpm.service" : "orbit-{$user}-php{$version}-fpm.service"];
            $pools[] = ['version' => $version, 'user' => $user, 'content' => ['blob' => ['content' => ValidatedData::string($file['content'])]]];
        }
        $admitted = array_map(static fn (array $file, mixed $test, mixed $reload): array => [ValidatedData::string($file['path']),
            ValidatedData::stringList(ValidatedData::object($test)['argv']), ValidatedData::string(ValidatedData::object($reload)['unit'])], $files, $tests, $reloads);
        if ($admitted !== $expected) {
            throw new EngineUnsupported('PHP-FPM pool commands outside the derived shapes run on Swamp.');
        }

        return $pools;
    }

    /** The first certificate of a signed reply: the leaf, without the root after it. */
    private static function leafPem(string $signed): string
    {
        $begin = '-----BEGIN CERTIFICATE-----';
        $parts = explode($begin, $signed);
        if (count($parts) < 2) {
            throw new RuntimeException('route.signed_certificate_missing');
        }

        return $begin.$parts[1];
    }

    /**
     * Signs one leaf's request through the same action as `operation:direct:certificate-sign`: it checks the admitted leaf,
     * the root, the run and that no cancel is pending, and records the Activity.
     *
     * @param  array<string, mixed>  $leaf
     */
    public function sign(Operation $operation, array $leaf, string $request, EngineRun $run): string
    {
        $certificates = $this->signer->handle($operation, ValidatedData::integer($operation->node_id), $run->runId(), [[
            'node_id' => ValidatedData::integer($leaf['node_id']), 'scope' => ValidatedData::string($leaf['scope']), 'state' => 'request',
            'request_base64' => base64_encode($request),
        ]]);
        $certificate = base64_decode(ValidatedData::string(($certificates[0] ?? [])['certificate_base64'] ?? ''), true);

        return is_string($certificate) && $certificate !== '' ? $certificate : throw new RuntimeException('certificate.sign_failed');
    }

    /**
     * A TLS handshake with one serving Node by its WireGuard address, the Route's name and the Orbit root alone: the chain and
     * the hostname must check. It needs no application response. Up to three tries a second apart, while Caddy loads the site.
     *
     * @param  array<string, mixed>  $probe
     */
    public function probe(array $probe): string
    {
        $address = ValidatedData::string($probe['address']);
        $domain = ValidatedData::string($probe['domain']);
        $authority = tempnam(sys_get_temp_dir(), 'orbit-probe-ca-');
        if ($authority === false || file_put_contents($authority, $this->root->rootCertificate()) === false) {
            throw new RuntimeException('route.probe_failed');
        }
        try {
            $context = stream_context_create(['ssl' => ['peer_name' => $domain, 'verify_peer' => true, 'verify_peer_name' => true,
                'allow_self_signed' => false, 'cafile' => $authority, 'SNI_enabled' => true]]);
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $socket = @stream_socket_client("ssl://{$address}:443", $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
                if (is_resource($socket)) {
                    fclose($socket);

                    return "verified {$domain} at {$address}";
                }
                if ($attempt < 3) {
                    sleep(1);
                }
            }
        } finally {
            @unlink($authority);
        }

        throw new RuntimeException("route.probe_failed: {$domain} at {$address}");
    }
}
