<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Certificates\SignDirectCertificatesAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\EngineAdmission;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Models\Operation;
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
final readonly class RouteEngineFlow implements EngineAdmission, EngineDefinition
{
    /** Each step prefix's chart label (NativeRouteConvergenceProjection::StepCodes). */
    private const array Labels = ['csr' => 'Make a CSR on the Node', 'sign' => 'Sign the CSR', 'site' => 'Install cert, publish site',
        'dns' => 'Publish private DNS', 'probe' => 'Probe the site over TLS'];

    private const array Unsupported = ['lan', 'ingress', 'ingress_ports', 'env_files', 'awake'];

    /** The executor's own shape rules (orbit-executor-route), checked here first so a mismatch starts on Swamp. */
    public const string Name = '/\A[a-z_][a-z0-9_-]{0,31}\z/D';

    public const string Scope = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D';

    private const string Directory = '#\A/etc/caddy/orbit-certificates/[A-Za-z0-9][A-Za-z0-9._-]*/versions/[0-9a-f]{16}\z#D';

    /**
     * The executor input that gives `tls.ensure_csr` its folder's owner and modes and the request's subject (ADR 0271 N3). A Node
     * whose executor does not report it runs the Route on Swamp.
     */
    public const string CsrFeature = 'tls.csr_input';

    public const string Domain = '/\A(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/D';

    /** Bytes a signed order adds around its input: the payload's other fields, base64 and the signature. */
    private const int OrderOverhead = 400;

    /** The host primitives route.create sends: a Node reports the `host` family (agent 0.36.0) when it lists each. */
    private const array HostFamily = ['config.apply', 'file.ensure', 'dotenv.ensure', 'systemd.unit.ensure', 'ca.trust.ensure'];

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
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            $node = ValidatedData::integer($leaf['node_id']);
            self::csrInput($leaf);
            $steps[] = new StepSpec("csr-{$index}", $node, 'tls.ensure_csr', (string) $node, budgetMs: 60_000, resendMs: 15_000, features: [self::CsrFeature]);
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
                self::fits($this->hostInput($plan, $node, $part, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
                $steps[] = new StepSpec($name, $node, $action, (string) $node, $after, budgetMs: $budget, resendMs: 15_000, features: $features);
                $after = [$name];
            }
            $last = [...$last, ...array_diff($after, $signed)];
        }
        // Private DNS follows the signatures, beside the sites, as the chart orders it. A probe reaches the Node by its
        // WireGuard address, so it waits for the sites alone.
        foreach (array_keys(RouteExecutionProof::objects($plan['dns'] ?? [])) as $index) {
            $vpn = ValidatedData::integer(RouteExecutionProof::objects($plan['dns'])[$index]['node_id']);
            $steps[] = new StepSpec("dns-{$index}", $vpn, 'config.apply', (string) $vpn, $signed, budgetMs: 120_000, resendMs: 20_000);
        }
        foreach (array_keys(RouteExecutionProof::objects($plan['probes'])) as $index) {
            $steps[] = new StepSpec("probe-{$index}", 0, 'route.probe', 'gateway', $last, budgetMs: 60_000);
        }

        return $steps;
    }

    /**
     * route.create runs on the engine only for a Route without Caddy access rows, whose every Node with work reports the `host`
     * family (each host primitive the flow sends), and whose every leaf Node also reports `tls.csr_input` and file.ensure
     * `source_path`. Any other route.create runs on Swamp, as a whole. Caddy access waits on a tree scope for posix.acl.ensure
     * (Nick's decision, RouteHostSteps::access), so a Route with access rows never reaches it on the engine.
     */
    public function admit(Operation $operation): void
    {
        $plan = self::plan($operation);
        if (($plan['acl'] ?? []) !== []) {
            throw new EngineUnsupported('A Route with Caddy access rows runs on Swamp until posix.acl.ensure takes a tree scope.');
        }
        $leaves = array_map(static fn (array $leaf): int => ValidatedData::integer($leaf['node_id']), RouteExecutionProof::objects($plan['leaves']));
        $nodes = array_values(array_unique([...ValidatedData::integerList($plan['site_nodes']), ...$leaves,
            ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['dns'] ?? []))]));
        foreach ($nodes as $node) {
            if (array_diff(self::HostFamily, $this->bus->nodeActions($node)) !== []) {
                throw new EngineUnsupported("Node {$node} lacks the host family; the Route runs on Swamp.");
            }
            if (in_array($node, $leaves, true) && array_diff([self::CsrFeature, RouteHostSteps::SourcePathFeature], $this->bus->nodeFeatures($node)) !== []) {
                throw new EngineUnsupported("Node {$node} lacks tls.csr_input or file.ensure source_path; the Route runs on Swamp.");
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
        foreach (array_keys(RouteHostSteps::access($mine('acl'))) as $index) {
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
            $parts[] = ['pools', 'config.apply', [], 180_000];
            foreach (array_keys($pools) as $index) {
                $parts[] = ["unit-{$index}", 'systemd.unit.ensure', [], 60_000];
            }
            $parts[] = ['reload', 'config.apply', [], 180_000];
        }
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            if (ValidatedData::integer($leaf['node_id']) === $node) {
                $parts = [...$parts, ["cert-{$index}", 'file.ensure', [], 60_000], ["current-{$index}", 'file.ensure', [], 60_000],
                    ["folder-{$index}", 'file.ensure', [], 60_000], ["key-{$index}", 'file.ensure', [RouteHostSteps::SourcePathFeature], 60_000],
                    ["copy-{$index}", 'file.ensure', [], 60_000], ["copylink-{$index}", 'file.ensure', [], 60_000]];
            }
        }
        if (in_array($node, ValidatedData::integerList($plan['trust']), true)) {
            $parts[] = ['trust', 'ca.trust.ensure', [], 120_000];
        }
        if ($mine('caddy') !== []) {
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
            'site' => $this->hostInput($plan, (int) $parts[1], $parts[2] ?? '', static fn (int $leaf): string => $run->output("sign-{$leaf}")
                ?? throw new LogicException("Leaf {$leaf} has no signed certificate.")),
            'dns' => self::dnsInput($plan, RouteExecutionProof::objects($plan['dns'])[(int) $parts[1]]),
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
            $failed !== null => ['status' => 'failed', 'error_code' => self::code($failed->name, $failed->error_code),
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
     * The code a failed step records, as route.publish_site and dns.publish_records answered it on main. A host primitive's
     * code maps onto the action's: a site step's input or content code keeps its kind and any other failure is
     * `route.publish_failed`; a DNS step's lock, check and restart codes keep their `dns.*` names. A guard refusal stays
     * `owned_revision_conflict`, as the guarded writers answer it.
     */
    public static function code(string $step, ?string $code): ?string
    {
        if ($code === null || $code === 'owned_revision_conflict') {
            return $code;
        }

        return match (explode('-', $step, 2)[0]) {
            'site' => match ($code) {
                'host.input_invalid' => 'route.input_invalid',
                'host.content_missing' => 'route.content_missing',
                default => 'route.publish_failed',
            },
            'dns' => match ($code) {
                'host.input_invalid' => 'dns.input_invalid',
                'host.content_missing' => 'dns.content_missing',
                'host.lock_busy', 'config.recovery_pending' => 'dns.publish_busy',
                'host.lock_failed' => 'dns.publish_lock_failed',
                'host.lock_invalid' => 'dns.publish_lock_invalid',
                'config.validate_failed' => 'dns.check_failed',
                'config.load_failed' => 'dns.restart_failed',
                default => 'dns.write_failed',
            },
            default => $code,
        };
    }

    /**
     * A VPN Node's records and catalog, as the Gateway rendered them, with the digests of the files they replace.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function dnsInput(array $plan, array $row): array
    {
        return RouteHostSteps::dns(ValidatedData::string($row['records']), ValidatedData::string($row['catalog']), self::guards($plan, ValidatedData::integer($row['node_id'])));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, string>
     */
    private static function guards(array $plan, int $node): array
    {
        $guards = is_array($plan['guards'] ?? null) ? $plan['guards'] : [];

        return RouteHostSteps::guards(is_array($guards[$node] ?? null) ? $guards[$node] : []);
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

    /**
     * The Node's account as the admitted plan names it: the user, its group and its home.
     *
     * @param  array<string, mixed>  $plan
     * @return array{string, string, string}
     */
    private static function account(array $plan, int $node): array
    {
        $nodes = is_array($plan['nodes'] ?? null) ? $plan['nodes'] : [];
        $accounts = is_array($plan['accounts'] ?? null) ? $plan['accounts'] : [];
        $user = is_array($nodes[$node] ?? null) ? ($nodes[$node]['account'] ?? null) : null;
        $owner = is_array($accounts[$node] ?? null) ? $accounts[$node] : [];
        if (! is_string($user) || ! is_string($owner['group'] ?? null) || ! is_string($owner['home'] ?? null)) {
            throw new EngineUnsupported("Node {$node} has no account.");
        }
        foreach ([$user, $owner['group']] as $name) {
            if (preg_match(self::Name, $name) !== 1) {
                throw new EngineUnsupported('An account outside the executor\'s shape runs on Swamp.');
            }
        }

        return [$user, $owner['group'], $owner['home']];
    }

    /**
     * One site step's primitive input. `$signed` gives a leaf's signed certificate by its index; content travels as blobs.
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(int): string  $signed
     * @return array<string, mixed>
     */
    private function hostInput(array $plan, int $node, string $part, callable $signed): array
    {
        $mine = self::mine($plan, $node);
        [$account, $group, $home] = self::account($plan, $node);
        [$kind, $index] = explode('-', $part, 2) + [1 => '0'];
        $index = (int) $index;
        $caddy = $mine('caddy');
        $named = static fn (string $name): string => preg_match(self::Name, $name) === 1 ? $name : throw new EngineUnsupported('An account outside the executor\'s shape runs on Swamp.');
        $pools = static fn (): array => RouteHostSteps::pools(self::pools($mine('php_files'), $mine('php_tests'), $mine('php_reloads')), $group, $home, self::guards($plan, $node));
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
            // The managed account's group shares its name (NodeEnrollmentPolicy).
            'link' => RouteHostSteps::link(ValidatedData::string($mine('links')[$index]['path']), ValidatedData::string($mine('links')[$index]['target']),
                $named(ValidatedData::string($mine('links')[$index]['owner'])), $named(ValidatedData::string($mine('links')[$index]['owner']))),
            'pools' => RouteHostSteps::apply(RouteHostSteps::CaddyLock, $pools()['files'], $pools()['validators']),
            'unit' => RouteHostSteps::running($pools()['units'][$index]),
            'reload' => RouteHostSteps::apply(RouteHostSteps::CaddyLock, $pools()['files'], $pools()['validators'],
                array_map(static fn (string $unit): array => ['unit' => $unit, 'load' => 'reload'], $pools()['units'])),
            'cert' => RouteHostSteps::plain($leaf()[2].'/cert.pem', self::leafPem($signed($index)), 'root', 'caddy', '0644'),
            'current' => RouteHostSteps::link($leaf()[3], $leaf()[1], 'root', 'root'),
            // `install -D` made the account copy's folders as root, 0755.
            'folder' => RouteHostSteps::directory(rtrim($home, '/').'/.orbit/certificates/'.$leaf()[0].'/'.$leaf()[1], 'root', 'root', '0755'),
            'key' => RouteHostSteps::copy(rtrim($home, '/').'/.orbit/certificates/'.$leaf()[0].'/'.$leaf()[1].'/key.pem', $leaf()[2].'/key.pem', $account, $group, '0600'),
            'copy' => RouteHostSteps::plain(rtrim($home, '/').'/.orbit/certificates/'.$leaf()[0].'/'.$leaf()[1].'/cert.pem', self::leafPem($signed($index)), $account, $group, '0600'),
            'copylink' => RouteHostSteps::link(rtrim($home, '/').'/.orbit/certificates/'.$leaf()[0].'/current', $leaf()[1], $account, $group),
            'trust' => ['state' => 'present', 'name' => RouteHostSteps::TrustName, 'certificate' => ['blob' => ['content' => ValidatedData::string($plan['root_certificate'])]],
                'owner' => ['user' => 'root', 'group' => 'root', 'mode' => '0644']],
            'caddy' => RouteHostSteps::caddy(ValidatedData::string(end($caddy)['content'] ?? null), self::guards($plan, $node)['caddy'] ?? null),
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
