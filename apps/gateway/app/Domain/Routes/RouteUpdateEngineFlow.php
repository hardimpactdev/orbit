<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
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

    /** Steps that run after the cutover commit, also on a forward retry. */
    private const array AfterCutover = ['grace', 'final', 'withdraw', 'sibling'];

    public function __construct(private RouteEngineFlow $create, private NativeRouteUpdateProjection $projection) {}

    public function steps(Operation $operation): array
    {
        $intent = self::intent($operation);
        $chain = self::chain($operation);
        if ($chain !== null && ! Engine::owns($chain)) {
            throw new EngineUnsupported('A retry of a Swamp-started update stays on Swamp.');
        }
        $resumed = $chain !== null && self::committedCutover($chain) ? $chain : null;
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
                $steps[] = new StepSpec("sign-{$index}", 0, 'certificate.sign', 'gateway', ["csr-{$index}"], budgetMs: 30_000);
                $signs[] = "sign-{$index}";
            }
            $sites = [];
            foreach (ValidatedData::integerList(ValidatedData::object($plan['publish'])['site_nodes']) as $node) {
                RouteEngineFlow::fits($this->phaseInput($plan, 'publish', $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
                $steps[] = new StepSpec("site-{$node}", $node, 'route.publish_site', (string) $node, $signs, budgetMs: 900_000, resendMs: 30_000);
                $sites[] = "site-{$node}";
            }
            foreach (array_keys(RouteExecutionProof::objects($plan['probes'])) as $index) {
                $steps[] = new StepSpec("probe-{$index}", 0, 'route.probe', 'gateway', $sites, budgetMs: 60_000);
                $before[] = "probe-{$index}";
            }
            foreach (RouteExecutionProof::objects($plan['dns']) as $index => $row) {
                self::guardedDnsInput($row);
                $vpn = ValidatedData::integer($row['node_id']);
                $steps[] = new StepSpec("dns-{$index}", $vpn, 'dns.publish_records', (string) $vpn, $before, budgetMs: 120_000, resendMs: 20_000);
                $before[] = "dns-{$index}";
            }
            $steps[] = new StepSpec('cutover', 0, 'route.cutover', 'gateway', $before, budgetMs: 30_000);
            $before = ['cutover'];
        }
        if ($plan['dns'] !== []) {
            $steps[] = new StepSpec('grace', 0, 'route.dns_grace', 'gateway', $before, budgetMs: 60_000);
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
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', $after === null ? $before : [$after], budgetMs: 60_000);
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
     * The unsettled update of the same Route and replacement that this Operation retries, if any. The chain's first engine
     * decides every retry: a Swamp-started chain stays on Swamp, and an engine-started one refuses rather than start Swamp.
     */
    public static function chain(Operation $operation): ?Operation
    {
        $intent = self::intent($operation);
        if ($intent['route_id'] === $intent['result_route_id']) {
            return null;
        }

        return Operation::query()->where('kind', self::Kind)->where('target_type', 'route')->where('target_id', $intent['route_id'])
            ->where('id', '<', $operation->id)->where('status', '!=', OperationStatus::Succeeded->value)->orderByDesc('id')->get()
            ->first(static fn (Operation $earlier): bool => (ValidatedData::object($earlier->input['route_update'] ?? [])['result_route_id'] ?? null) === $intent['result_route_id']
                && isset($earlier->state['swamp_run_id']));
    }

    /** Refuses a Swamp start of an update whose chain began on the engine (StartSwampRun, after EngineKinds::routes). */
    public static function assertSwampMayRun(Operation $operation): void
    {
        if ($operation->kind !== self::Kind || ! is_array($operation->input['route_update'] ?? null)) {
            return;
        }
        $chain = self::chain($operation);
        if ($chain !== null && Engine::owns($chain)) {
            throw new ResourceOperationException('route.update_engine_chain', 'This Route update began on the engine; its retry runs there or not at all.', 409);
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

    /** The grace remainder from the stored DNS receipts: a resend or a retry never shortens it. */
    private static function grace(EngineRun $chain): string
    {
        $received = max(array_map(static fn (OperationEngineStep $step): int => (int) $step->done_ms,
            array_filter($chain->steps, static fn (OperationEngineStep $step): bool => str_starts_with($step->name, 'dns-') && $step->status === 'done')) ?: [0]);
        if ($received === 0) {
            throw new RuntimeException('route.dns_grace_failed: no stored DNS receipt');
        }
        $remaining = $received + self::GraceMs - Engine::nowMs();
        if ($remaining > 0) {
            usleep($remaining * 1000);
        }

        return 'grace ended '.($received + self::GraceMs);
    }

    /** Another Route on the Node still answers after the old site left; a Node that serves no other Route has nothing to check. */
    private function sibling(Operation $operation, int $node): string
    {
        $intent = self::intent($operation);
        $sibling = Route::query()->whereNotIn('id', [$intent['route_id'], $intent['result_route_id']])->where('status', RouteStatus::Active->value)
            ->where('sites_published', true)->whereHas('targets.instance', static fn ($query) => $query->where('node_id', $node))->orderBy('id')->first();
        $address = app(RouteRenderContext::class)->wireguardIp(Node::query()->findOrFail($node));
        if (! $sibling instanceof Route || ! is_string($address)) {
            return "no sibling on node {$node}";
        }

        return $this->create->probe(['address' => $address, 'domain' => $sibling->domain]);
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
        foreach (['ingress', 'ingress_ports', 'lan_absent'] as $list) {
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
        $chain = self::chain($operation);
        if ($chain === null || ! self::committedCutover($chain)) {
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
