<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineStepNotYet;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * `route:update` on the state machine (ADR 0271; docs/cli/route/engine/update.mmd): a `--domain` change of a private Route.
 * It reads the plan the Gateway admitted (`route_update.plan`, NativeRouteUpdatePlan) and runs it as typed steps:
 *
 * - `csr-<i>`, `sign-<i>`: the leaves, as route.create;
 * - `site-<node>`: `route.publish_site` with the publish phase, whose Caddyfile serves the old and the new name side by side;
 * - `dns-<i>`: the guarded `dns.publish_records` of ORB-6310 on each VPN Node ([self::guardedDnsInput]);
 * - `probe-<i>`: the TLS handshake with each serving Node under the new name;
 * - `cutover`: the Gateway commits the replacement authoritative and the old Route `retiring` ([self::cutover]);
 * - `grace`: the DNS grace, timed from the stored DNS receipts;
 * - `final-<node>`: `route.publish_site` with the retire phase, the lasting Caddyfile and the late leaves, read back by the action;
 * - `withdraw-<node>`: route.destroy's removal action for the old domain's leaves ([self::RemovalAction]);
 * - `sibling-<node>`: another Route on that Node still answers.
 *
 * A retry of the same update is forward only and never changes engine ([self::chain]): after the cutover it runs only the
 * post-cutover steps, from the plan and receipts that the chain's cutover Operation stored. A public Route, a publication
 * change, a LAN or Ingress rule and every URL write (`APP_URL` lines and whole `.env` files) start on Swamp.
 */
final readonly class RouteUpdateEngineFlow implements EngineDefinition
{
    public const string Kind = 'route.update';

    /**
     * route.destroy's site withdrawal (ORB-6310), which a Node must report before an update that retires a leaf leaves Swamp.
     * No Node reports it yet, so such an update starts on Swamp.
     */
    public const string RemovalAction = 'route.withdraw_site';

    /** TTL 30 s and 1 s: resolvers that honor the TTL stop asking for the old name before its site leaves. */
    private const int GraceMs = 31_000;

    /** Each step prefix's chart label (NativeRouteUpdateProjection::StepCodes). */
    private const array Labels = ['csr' => 'Make a CSR on the Node', 'sign' => 'Sign the CSR', 'site' => 'Install cert, publish site',
        'probe' => 'Probe the site over TLS', 'dns' => 'Move private DNS', 'cutover' => 'Move private DNS', 'grace' => 'Wait the DNS grace',
        'final' => 'Withdraw the old site', 'withdraw' => 'Withdraw the old site', 'sibling' => 'Withdraw the old site'];

    /**
     * When a Gateway step marked sent runs again because its worker was lost (Engine, receipt-bound runs): past the longest
     * run of any of them, a probe's three tries of 5 s each. Each is idempotent.
     */
    private const int LocalResendMs = 60_000;

    /** Steps that run after the cutover commit, also on a forward retry. */
    private const array AfterCutover = ['grace', 'final', 'withdraw', 'sibling'];

    public function __construct(private RouteEngineFlow $create, private NativeRouteUpdateProjection $projection) {}

    public function steps(Operation $operation): array
    {
        $intent = self::intent($operation);
        if (self::owner($operation) === 'swamp') {
            throw new EngineUnsupported('A retry of a Swamp-started update stays on Swamp.');
        }
        $resumed = self::cutoverOwner($operation);
        $plan = self::plan($resumed ?? $operation);
        self::admit($intent, $plan);

        $steps = [];
        $before = [];
        if ($resumed === null) {
            $signs = [];
            foreach (array_keys(RouteExecutionProof::objects($plan['leaves'])) as $index) {
                $node = ValidatedData::integer(RouteExecutionProof::objects($plan['leaves'])[$index]['node_id']);
                $this->create->csrInput(RouteExecutionProof::objects($plan['leaves'])[$index]);
                $steps[] = new StepSpec("csr-{$index}", $node, 'tls.ensure_csr', (string) $node, budgetMs: 60_000, resendMs: 15_000);
                $steps[] = new StepSpec("sign-{$index}", 0, 'certificate.sign', 'gateway', ["csr-{$index}"], budgetMs: 30_000, resendMs: self::LocalResendMs);
                $signs[] = "sign-{$index}";
            }
            $sites = [];
            foreach (ValidatedData::integerList(ValidatedData::object($plan['publish'])['site_nodes']) as $node) {
                RouteEngineFlow::fits($this->phaseInput($plan, 'publish', $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
                $steps[] = new StepSpec("site-{$node}", $node, 'route.publish_site', (string) $node, $signs, budgetMs: 900_000, resendMs: 30_000);
                $sites[] = "site-{$node}";
            }
            foreach (array_keys(RouteExecutionProof::objects($plan['probes'])) as $index) {
                $steps[] = new StepSpec("probe-{$index}", 0, 'route.probe', 'gateway', $sites, budgetMs: 60_000, resendMs: self::LocalResendMs);
                $before[] = "probe-{$index}";
            }
            foreach (RouteExecutionProof::objects($plan['dns']) as $index => $row) {
                self::guardedDnsInput($row);
                $vpn = ValidatedData::integer($row['node_id']);
                $steps[] = new StepSpec("dns-{$index}", $vpn, 'dns.publish_records', (string) $vpn, $before, budgetMs: 120_000, resendMs: 20_000);
                $before[] = "dns-{$index}";
            }
            $steps[] = new StepSpec('cutover', 0, 'route.cutover', 'gateway', $before, budgetMs: 30_000, resendMs: self::LocalResendMs);
            $before = ['cutover'];
        }
        if ($plan['dns'] !== []) {
            $steps[] = new StepSpec('grace', 0, 'route.dns_grace', 'gateway', $before, budgetMs: 60_000, resendMs: self::LocalResendMs);
            $before = ['grace'];
        }
        $finals = [];
        foreach (ValidatedData::integerList(ValidatedData::object($plan['retire'])['site_nodes']) as $node) {
            RouteEngineFlow::fits($this->phaseInput($plan, 'retire', $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
            $steps[] = new StepSpec("final-{$node}", $node, 'route.publish_site', (string) $node, $before, budgetMs: 900_000, resendMs: 30_000);
            $finals[$node] = "final-{$node}";
        }
        $withdrawn = [];
        foreach (array_unique(array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($plan['cleanup']))) as $node) {
            $steps[] = new StepSpec("withdraw-{$node}", $node, self::RemovalAction, (string) $node, isset($finals[$node]) ? [$finals[$node]] : $before, budgetMs: 120_000, resendMs: 20_000);
            $withdrawn[$node] = "withdraw-{$node}";
        }
        foreach (ValidatedData::integerList($plan['retire_nodes']) as $node) {
            $after = $withdrawn[$node] ?? $finals[$node] ?? null;
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', $after === null ? $before : [$after], budgetMs: 60_000, resendMs: self::LocalResendMs);
        }

        return $steps;
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $source = self::source($operation, $run);
        $plan = self::plan($source['operation']);
        [$kind, $index] = explode('-', $step, 2);
        $signed = static fn (int $leaf): string => $source['run']->output("sign-{$leaf}") ?? throw new LogicException("Leaf {$leaf} has no signed certificate.");

        return match ($kind) {
            'csr' => $this->create->csrInput(RouteExecutionProof::objects($plan['leaves'])[(int) $index]),
            'site' => $this->phaseInput($plan, 'publish', (int) $index, $signed),
            'final' => $this->phaseInput($plan, 'retire', (int) $index, $signed),
            'dns' => self::guardedDnsInput(RouteExecutionProof::objects($plan['dns'])[(int) $index]),
            'withdraw' => self::removalInput($plan, (int) $index),
            default => throw new LogicException("Step [{$step}] is a Gateway step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        $source = self::source($operation, $run);
        $plan = self::plan($source['operation']);
        $parts = explode('-', $step, 2);

        return match ($parts[0]) {
            'sign' => $this->create->sign($operation, RouteExecutionProof::objects($plan['leaves'])[(int) $parts[1]], $run->output("csr-{$parts[1]}") ?? '', $run),
            'probe' => $this->create->probe(RouteExecutionProof::objects($plan['probes'])[(int) $parts[1]]),
            'cutover' => $this->cutover($operation),
            'grace' => self::grace($source['run']),
            'sibling' => $this->sibling($operation, (int) $parts[1]),
            default => throw new LogicException("Step [{$step}] runs on a Node."),
        };
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        if ($failed !== null && $operation->cancel_requested_at !== null && str_starts_with($failed->name, 'sign-')) {
            $failed = null;
        }
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => self::chartStep($failed->name, self::committed($operation, $run)), 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /**
     * A step's name as the chart's label for NativeRouteUpdateProjection. Once the cutover is committed every failure is a
     * post-cutover label, so the projection keeps the replacement authoritative and never demotes it.
     */
    public static function chartStep(string $name, bool $committed): string
    {
        [$kind] = explode('-', $name, 2);
        $label = self::Labels[$kind] ?? throw new LogicException("Step [{$name}] has no chart label.");

        return $committed && ! in_array($kind, self::AfterCutover, true) ? self::Labels['withdraw'] : $label;
    }

    /**
     * The earlier unsettled attempts of the same Route and replacement that this Operation retries, oldest first.
     *
     * @return list<Operation>
     */
    public static function attempts(Operation $operation): array
    {
        $intent = self::intent($operation);
        if ($intent['route_id'] === $intent['result_route_id']) {
            return [];
        }

        return array_values(Operation::query()->where('kind', self::Kind)->where('target_type', 'route')->where('target_id', $intent['route_id'])
            ->where('id', '<', $operation->id)->where('status', '!=', OperationStatus::Succeeded->value)->orderBy('id')->get()
            ->filter(static fn (Operation $earlier): bool => (ValidatedData::object($earlier->input['route_update'] ?? [])['result_route_id'] ?? null) === $intent['result_route_id'])
            ->all());
    }

    /**
     * Which engine owns the update chain: `engine` once an attempt ran there, `swamp` once one was bound to or may have reached
     * Swamp (an uncertain start included), null before either. The owner decides every retry.
     */
    public static function owner(Operation $operation): ?string
    {
        $owners = [];
        foreach (self::attempts($operation) as $earlier) {
            if (Engine::owns($earlier)) {
                $owners['engine'] = true;
            } elseif (isset($earlier->state['swamp_run_id']) || $earlier->error_code === 'swamp.start_uncertain') {
                $owners['swamp'] = true;
            }
        }
        if (count($owners) > 1) {
            throw new ResourceOperationException('route.update_engine_chain', 'This Route update ran on both engines; it needs an operator.', 409);
        }

        return array_key_first($owners);
    }

    /**
     * Holds, with the Operation's start, each Node a step writes: the publish and retire phases' Nodes, the withdrawal's and
     * the VPN Nodes of the DNS move, of this plan and of the plan it resumes. A Node another write holds refuses the start.
     */
    public static function holdNodes(Operation $operation): void
    {
        if ($operation->kind !== self::Kind) {
            return;
        }
        $nodes = [];
        foreach (array_filter([$operation, self::cutoverOwner($operation)]) as $from) {
            $plan = self::plan($from);
            foreach (['publish', 'retire'] as $phase) {
                array_push($nodes, ...ValidatedData::integerList(ValidatedData::object($plan[$phase] ?? [])['site_nodes'] ?? []));
            }
            foreach (['dns', 'cleanup'] as $list) {
                foreach (RouteExecutionProof::objects($plan[$list] ?? []) as $row) {
                    $nodes[] = ValidatedData::integer($row['node_id']);
                }
            }
        }
        if (! app(RoutePublicationHolds::class)->holdEngine($operation, $nodes)) {
            throw new ResourceOperationException('route.publication_held', 'Another Route write holds a Node of this update.', 409);
        }
    }

    /** The attempt whose cutover step is done: every later attempt resumes from its stored plan, leaves and receipts. */
    public static function cutoverOwner(Operation $operation): ?Operation
    {
        foreach (self::attempts($operation) as $earlier) {
            if (Engine::owns($earlier) && self::committedCutover($earlier)) {
                return $earlier;
            }
        }

        return null;
    }

    /**
     * Refuses a start that would mix engines or replay a stale file (StartSwampRun, before the engine decision): a Swamp start
     * of an engine-owned chain, and a resumed lasting Caddyfile that no longer equals the one rendered now, because a sibling
     * Route was published or withdrawn on that Node since the cutover. The same command run again after the sibling settles
     * renders the same file.
     */
    public static function assertChain(Operation $operation, bool $engine): void
    {
        if ($operation->kind !== self::Kind || ! is_array($operation->input['route_update'] ?? null)) {
            return;
        }
        $owner = self::owner($operation);
        if (! $engine && $owner === 'engine') {
            throw new ResourceOperationException('route.update_engine_chain', 'This Route update began on the engine; its retry runs there or not at all.', 409);
        }
        $resumed = $engine ? self::cutoverOwner($operation) : null;
        if ($resumed !== null) {
            $caddy = static fn (Operation $from): array => array_column(RouteExecutionProof::objects(ValidatedData::object(self::plan($from)['retire'] ?? [])['caddy'] ?? []), 'content', 'node_id');
            if ($caddy($resumed) !== $caddy($operation)) {
                throw new ResourceOperationException('route.publication_stale', 'A Route changed on this update\'s Nodes since its cutover; run the update again once it settles.', 409);
            }
        }
    }

    /**
     * The guarded input of `dns.publish_records` (ORB-6310): per file, the digest the Gateway rendered from and the digest of
     * the desired bytes. Until ORB-6310 lands that input, an update with private DNS starts on Swamp.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function guardedDnsInput(array $row): array
    {
        throw new EngineUnsupported('A Route update with private DNS needs the guarded DNS publication of ORB-6310.');
    }

    /**
     * route.destroy's removal input (ORB-6310) for the old domain's leaves on one Node.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function removalInput(array $plan, int $node): array
    {
        $leaves = array_values(array_map(static fn (array $row): array => ValidatedData::object($row['leaf']),
            array_filter(RouteExecutionProof::objects($plan['cleanup']), static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node)));

        return ['leaves' => $leaves];
    }

    /**
     * The cutover commit: one transaction makes the replacement authoritative and the old Route `retiring`. The step's receipt
     * row is its acknowledgement; a rerun after a crash between the two finds the commit and answers the same output.
     */
    private function cutover(Operation $operation): string
    {
        $intent = self::intent($operation);

        return DB::transaction(function () use ($intent): string {
            $source = Route::query()->lockForUpdate()->findOrFail($intent['route_id']);
            $selected = Route::query()->lockForUpdate()->findOrFail($intent['result_route_id']);
            if ($source->status !== RouteStatus::Retiring) {
                app(RouteMutationState::class)->cutover($source, $selected);
                $source->update(['status' => RouteStatus::Retiring]);
            }
            $selected->update([...ValidatedData::object($intent['selected']), 'status' => RouteStatus::Active, 'sites_published' => true,
                'failed_step' => null, 'error_code' => null]);

            return "cutover {$source->id} to {$selected->id}";
        });
    }

    /** The grace from the stored DNS receipts: until it ends the step waits without a worker; a resend or a retry never shortens it. */
    private static function grace(EngineRun $chain): string
    {
        $received = max(array_map(static fn (OperationEngineStep $step): int => (int) $step->done_ms,
            array_filter($chain->steps, static fn (OperationEngineStep $step): bool => str_starts_with($step->name, 'dns-') && $step->status === 'done')) ?: [0]);
        if ($received === 0) {
            throw new RuntimeException('route.dns_grace_failed: no stored DNS receipt');
        }
        if (Engine::nowMs() < $received + self::GraceMs) {
            throw new EngineStepNotYet($received + self::GraceMs);
        }

        return 'grace ended '.($received + self::GraceMs);
    }

    /**
     * Another site the Node serves still answers after the old site left, whatever kind of Route serves it (an Instance's, a
     * proxy's or a Router's). Only a Node that serves no other site has nothing to check; a Node without an address fails.
     */
    private function sibling(Operation $operation, int $node): string
    {
        $intent = self::intent($operation);
        $own = array_values(array_filter([Route::query()->find($intent['route_id'])?->domain, Route::query()->find($intent['result_route_id'])?->domain]));
        $served = Node::query()->findOrFail($node);
        $probe = self::siblingProbe($served, array_values(app(DevelopmentSiteRepository::class)->forNode($served)->all()), $own);

        return $probe === null ? "no sibling on node {$node}" : $this->create->probe($probe);
    }

    /**
     * The probe of the first other site a Node serves, by its WireGuard address; null when it serves no other site.
     *
     * @param  list<DevelopmentSite>  $sites
     * @param  list<string>  $own  The update's old and new names.
     * @return array{address: string, domain: string}|null
     */
    public static function siblingProbe(Node $node, array $sites, array $own): ?array
    {
        $domains = array_values(array_unique(array_filter(array_map(static fn (DevelopmentSite $site): string => $site->domain, $sites),
            static fn (string $domain): bool => ! in_array($domain, $own, true) && ! str_starts_with($domain, '*.'))));
        sort($domains);
        if ($domains === []) {
            return null;
        }
        $address = app(RouteRenderContext::class)->wireguardIp($node);
        if (! is_string($address)) {
            throw new RuntimeException("route.probe_failed: node {$node->id} has no WireGuard address");
        }

        return ['address' => $address, 'domain' => $domains[0]];
    }

    /**
     * One phase's `route.publish_site` input on one Node: the phase's lists and only its own leaves.
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(int): string  $signed
     * @return array<string, mixed>
     */
    private function phaseInput(array $plan, string $phase, int $node, callable $signed): array
    {
        $lists = ValidatedData::object($plan[$phase]);

        return $this->create->siteInput($lists, $node, $signed, ValidatedData::integerList($lists['leaf_indexes']));
    }

    /**
     * The shapes the engine runs: a domain change of a private Route with no URL write, LAN or Ingress rule.
     *
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $plan
     */
    private static function admit(array $intent, array $plan): void
    {
        if ($intent['mode'] !== 'replace_domain' || $intent['effectful'] !== true || $intent['public_activated'] !== false
            || ValidatedData::object($intent['selected'])['publication'] !== RoutePublication::Private->value
            || ValidatedData::object($intent['before'])['publication'] !== RoutePublication::Private->value) {
            throw new EngineUnsupported('Only a private Route\'s domain change runs on the engine.');
        }
        // route.destroy's withdrawal is not wired on either side yet (RemovalAction): an update that withdraws a leaf stays on Swamp,
        // whatever its Nodes report.
        foreach (['ingress', 'ingress_ports', 'lan_absent', 'cleanup'] as $list) {
            if (($plan[$list] ?? []) !== []) {
                throw new EngineUnsupported("route.update with {$list} runs on Swamp.");
            }
        }
        foreach (['publish', 'app_url', 'retire'] as $phase) {
            $lists = ValidatedData::object($plan[$phase]);
            // APP_URL lines and whole .env files are written by Swamp alone (operation-route-update).
            foreach (['url_lines', 'env_files', 'lan'] as $list) {
                if (($lists[$list] ?? []) !== []) {
                    throw new EngineUnsupported("route.update with {$phase} {$list} runs on Swamp.");
                }
            }
        }
    }

    /**
     * Where a step's plan and receipts live: this Operation, or on a forward retry the chain's Operation that committed the
     * cutover, whose stored plan, signed leaves and DNS receipt time the retry repeats.
     *
     * @return array{operation: Operation, run: EngineRun}
     */
    private static function source(Operation $operation, EngineRun $run): array
    {
        if (isset($run->steps['cutover'])) {
            return ['operation' => $operation, 'run' => $run];
        }
        $chain = self::cutoverOwner($operation);
        if ($chain === null) {
            return ['operation' => $operation, 'run' => $run];
        }
        $steps = OperationEngineStep::query()->where('operation_id', $chain->id)->get()->keyBy('name')->all();

        return ['operation' => $chain, 'run' => new EngineRun($chain->id, $steps, 0, null)];
    }

    private static function committedCutover(Operation $operation): bool
    {
        return OperationEngineStep::query()->where('operation_id', $operation->id)->where('name', 'cutover')->where('status', 'done')->exists();
    }

    /** Whether this run, or the chain it resumes, committed the cutover. */
    private static function committed(Operation $operation, EngineRun $run): bool
    {
        $cutover = $run->steps['cutover'] ?? null;

        return $cutover === null || $cutover->status === 'done';
    }

    /** @return array{route_id: int, result_route_id: int, mode: string, effectful: bool, selected: mixed, before: mixed, public_activated: mixed} */
    private static function intent(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['route_update'] ?? []);

        return ['route_id' => ValidatedData::integer($intent['route_id'] ?? 0), 'result_route_id' => ValidatedData::integer($intent['result_route_id'] ?? 0),
            'mode' => ValidatedData::string($intent['mode'] ?? ''), 'effectful' => ($intent['effectful'] ?? false) === true,
            'selected' => $intent['selected'] ?? null, 'before' => $intent['before'] ?? null, 'public_activated' => $intent['public_activated'] ?? false];
    }

    /** @return array<string, mixed> */
    private static function plan(Operation $operation): array
    {
        return ValidatedData::object(ValidatedData::object($operation->input['route_update'] ?? [])['plan'] ?? []);
    }
}
