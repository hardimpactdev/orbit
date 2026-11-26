<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\ReceiptBoundDefinition;
use App\Domain\Engine\StepSpec;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * `route:update --domain` of a private Route on the state machine (ADR 0271; docs/cli/route/engine/update.mmd). It reads the plan
 * the Gateway admitted (`route_update.plan`, NativeRouteUpdatePlan) and runs it as typed steps:
 *
 * - `csr-<i>`, `sign-<i>`: the new leaves, as route.create;
 * - `site-<node>`: `route.publish_site` with the prepared Caddyfile (old and new site side by side) and the new `APP_URL`;
 * - `probe-<i>`: a TLS handshake with the new name on each serving Node, before any switch;
 * - `dns-<i>`: the guarded `dns.publish_records` moving the answers to the new name;
 * - `cutover`: the Gateway records the replacement authoritative and the old Route retiring;
 * - `grace`, `withdraw-<node>`, `sibling-<node>`: the old site leaves as in route.destroy, and another Route on the Node still answers.
 *
 * Any other update shape starts on Swamp ([EngineUnsupported]). Step labels map onto the Swamp chart's, so the projection
 * records the same `failed_step`, error code and, after the cutover, the same forward recovery.
 */
final readonly class RouteUpdateEngineFlow implements EngineDefinition, ReceiptBoundDefinition
{
    /** Each step prefix's Swamp chart label (NativeRouteUpdateProjection::StepCodes). The sibling check closes the old site's withdrawal. */
    private const array Labels = ['csr' => 'Make a CSR on the Node', 'sign' => 'Sign the CSR', 'site' => 'Install cert, publish site',
        'probe' => 'Probe the site over TLS', 'dns' => 'Move private DNS', 'grace' => 'Wait the DNS grace', 'withdraw' => 'Withdraw the old site',
        'late' => 'Withdraw the old site', 'sibling' => 'Withdraw the old site'];

    public function __construct(private NativeRouteUpdateProjection $projection, private RouteEngineFlow $create, private RouteDestroyEngineFlow $destroy) {}

    public function steps(Operation $operation): array
    {
        $intent = self::intent($operation);
        $plan = self::plan($operation);
        if ($intent['effectful'] !== true || ($intent['mode'] ?? null) !== 'replace_domain' || $intent['route_id'] === $intent['result_route_id']
            || ($intent['public_activated'] ?? false) !== false || ($intent['analytics_changes'] ?? []) !== []
            || ValidatedData::object($intent['selected'])['publication'] !== 'private' || (ValidatedData::object($intent['before'])['publication'] ?? null) !== 'private') {
            throw new EngineUnsupported('Only a private Route\'s domain change runs on the engine.');
        }
        // The identical request after a failure past the cutover: the authority switch stands, so only the old site's withdrawal runs.
        if ((ValidatedData::object($intent['before'])['status'] ?? null) !== RouteStatus::Active->value) {
            return $this->resumeSteps($operation);
        }
        foreach (['ingress', 'ingress_ports', 'edge_nodes', 'lan_absent'] as $list) {
            if (($plan[$list] ?? []) !== []) {
                throw new EngineUnsupported("route.update with {$list} runs on Swamp.");
            }
        }
        $publish = ValidatedData::object($plan['publish']);
        $url = ValidatedData::object($plan['app_url']);
        $retire = ValidatedData::object($plan['retire']);
        // Swamp writes APP_URL only after every site published, under its own step and code; the engine takes no URL write.
        if (($publish['lan'] ?? []) !== [] || ($publish['env_files'] ?? []) !== [] || ($publish['url_lines'] ?? []) !== [] || ($url['env_files'] ?? []) !== []
            || ($url['url_lines'] ?? []) !== []
            || array_diff(ValidatedData::integerList($url['site_nodes']), ValidatedData::integerList($publish['site_nodes'])) !== []
            || self::sorted([...ValidatedData::integerList($publish['leaf_indexes']), ...ValidatedData::integerList($retire['leaf_indexes'])])
                !== self::sorted(array_keys(RouteExecutionProof::objects($plan['leaves'])))) {
            throw new EngineUnsupported('A URL write other than APP_URL or a LAN rule runs on Swamp.');
        }
        foreach (['acl', 'links', 'php_files', 'php_tests', 'php_reloads', 'url_lines', 'env_files', 'lan'] as $list) {
            if (($retire[$list] ?? []) !== []) {
                throw new EngineUnsupported("An old-site withdrawal with {$list} runs on Swamp.");
            }
        }
        $steps = $signs = $sites = $probes = $dns = [];
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            $node = ValidatedData::integer($leaf['node_id']);
            RouteEngineFlow::csrInput($leaf);
            $steps[] = new StepSpec("csr-{$index}", $node, 'tls.ensure_csr', (string) $node, budgetMs: 60_000, resendMs: 15_000);
            $steps[] = $signs[] = new StepSpec("sign-{$index}", 0, 'certificate.sign', 'gateway', ["csr-{$index}"], budgetMs: 30_000);
        }
        $names = static fn (array $specs): array => array_values(array_map(static fn (StepSpec $step): string => $step->name, $specs));
        foreach (ValidatedData::integerList($publish['site_nodes']) as $node) {
            RouteEngineFlow::fits($this->siteInput($operation, $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
            $steps[] = $sites[] = new StepSpec("site-{$node}", $node, 'route.publish_site', (string) $node, $names($signs), budgetMs: 900_000, resendMs: 30_000);
        }
        foreach (array_keys(RouteExecutionProof::objects($plan['probes'])) as $index) {
            $steps[] = $probes[] = new StepSpec("probe-{$index}", 0, 'route.probe', 'gateway', $names($sites), budgetMs: 60_000);
        }
        // The answers move only after every probe saw the new name served.
        foreach (array_keys(RouteExecutionProof::objects($plan['dns'])) as $index) {
            $vpn = ValidatedData::integer(RouteExecutionProof::objects($plan['dns'])[$index]['node_id']);
            $this->dnsInput($operation, $index);
            $steps[] = $dns[] = new StepSpec("dns-{$index}", $vpn, 'dns.publish_records', (string) $vpn, $names($probes),
                budgetMs: 120_000, resendMs: 20_000, features: [RouteDestroyEngineFlow::GuardedDns]);
        }
        $steps[] = new StepSpec('cutover', 0, 'route.cutover', 'gateway', [...$names($probes), ...$names($dns)], budgetMs: 30_000);
        $after = ['cutover'];
        if ($dns !== []) {
            $steps[] = new StepSpec('grace', 0, 'route.dns_grace', 'gateway', ['cutover'], budgetMs: 60_000);
            $after = ['grace'];
        }
        $late = [];
        foreach (ValidatedData::integerList($retire['leaf_indexes']) as $index) {
            $late[ValidatedData::integer(RouteExecutionProof::objects($plan['leaves'])[$index]['node_id'])] = true;
        }
        foreach (array_keys($late) as $node) {
            // A new version of a scope the old site still names installs only after the cutover.
            RouteEngineFlow::fits($this->lateInput($operation, $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
            $steps[] = new StepSpec("late-{$node}", $node, 'route.publish_site', (string) $node, $after, budgetMs: 300_000, resendMs: 30_000);
        }
        foreach (array_values(array_unique(ValidatedData::integerList($plan['retire_nodes']))) as $node) {
            RouteEngineFlow::fits($this->withdrawInput($operation, $node));
            $steps[] = new StepSpec("withdraw-{$node}", $node, 'route.withdraw_site', (string) $node, isset($late[$node]) ? [...$after, "late-{$node}"] : $after,
                budgetMs: 600_000, resendMs: 30_000);
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', ["withdraw-{$node}"], budgetMs: 60_000);
        }

        return $steps;
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        [$kind, $index] = explode('-', $step, 2);

        return match ($kind) {
            'csr' => RouteEngineFlow::csrInput(RouteExecutionProof::objects(self::plan($operation)['leaves'])[(int) $index]),
            'site' => $this->siteInput($operation, (int) $index, static fn (int $leaf): string => $run->output("sign-{$leaf}")
                ?? throw new LogicException("Leaf {$leaf} has no signed certificate.")),
            'late' => $this->lateInput($source = self::resumed($operation) ?? $operation, (int) $index,
                static fn (int $leaf): string => ($source === $operation ? $run : self::recorded($source))->output("sign-{$leaf}")
                ?? throw new LogicException("Leaf {$leaf} has no signed certificate.")),
            'dns' => $this->dnsInput($operation, (int) $index),
            'withdraw' => $this->withdrawInput(self::resumed($operation) ?? $operation, (int) $index),
            default => throw new LogicException("Step [{$step}] is a Gateway step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        $plan = self::plan($operation);
        [$kind] = explode('-', $step, 2);
        $index = (int) (explode('-', $step, 2)[1] ?? 0);

        return match ($kind) {
            'sign' => $this->create->sign($operation, RouteExecutionProof::objects($plan['leaves'])[$index], $run->output("csr-{$index}") ?? '', $run),
            'probe' => $this->create->probe(RouteExecutionProof::objects($plan['probes'])[$index]),
            'cutover' => $this->cutover($operation),
            'grace', 'sibling' => $this->destroy->local($this->old($operation), $step, ($earlier = self::resumed($operation)) !== null ? self::recorded($earlier) : $run),
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
        $cut = ($run->steps['cutover'] ?? null)?->status === 'done';
        // A cancel after the cutover cannot give the old name back: the projection records it as a failure after the cutover,
        // which keeps the replacement authoritative and the old Route retiring for a forward retry.
        $pending = $cut ? array_find(array_keys($run->steps), static fn (string $name): bool => $name !== 'cutover' && $run->steps[$name]->status !== 'done'
            && ($name === 'grace' || str_starts_with($name, 'late-') || str_starts_with($name, 'withdraw-') || str_starts_with($name, 'sibling-'))) : null;
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => $this->failedStep($operation, $failed->name), 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            $pending !== null => ['status' => 'failed', 'error_code' => 'operation.cancelled',
                'failure' => ['failed_step' => self::chartStep($pending), 'stderr_recorded' => false]],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /**
     * A failed step as the Swamp chart names it. The cutover step has no chart step: when its authority transaction committed
     * (the old Route retiring, the replacement at the database cutover) the failure is after the cutover and recovers forward;
     * otherwise it is a failure before it, with no chart step, so the old Route stays authoritative.
     */
    private function failedStep(Operation $operation, string $name): string
    {
        if ($name !== 'cutover') {
            return self::chartStep($name);
        }
        $intent = self::intent($operation);
        $source = Route::query()->find(ValidatedData::integer($intent['route_id']));
        $selected = Route::query()->find(ValidatedData::integer($intent['result_route_id']));
        $committed = $source?->status === RouteStatus::Retiring && $selected?->replacement_step === RouteReplacementStep::DatabaseCutover;

        return $committed ? self::chartStep('withdraw-0') : 'cutover';
    }

    /** A step's name as the Swamp chart's iteration, such as `withdraw-3` → `Withdraw the old site-3`. */
    public static function chartStep(string $name): string
    {
        [$kind] = explode('-', $name, 2);
        $index = str_contains($name, '-') ? explode('-', $name, 2)[1] : '0';

        return (self::Labels[$kind] ?? throw new LogicException("Step [{$name}] has no chart label.")).'-'.$index;
    }

    /**
     * Records the cutover once the new name is served and answered: the replacement is authoritative and the old Route retiring
     * (RouteMutationState::cutover, which the terminal projection repeats). A repeat finds it recorded.
     */
    private function cutover(Operation $operation): string
    {
        $intent = self::intent($operation);

        return DB::transaction(function () use ($intent): string {
            $source = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['route_id']));
            $selected = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['result_route_id']));
            app(RouteMutationState::class)->cutover($source, $selected);

            return "route {$selected->id} authoritative, route {$source->id} retiring";
        });
    }

    /**
     * One Node's `route.publish_site` input: the publish phase with the new `APP_URL` lines, as route.create renders it.
     *
     * @param  callable(int): string  $signed
     * @return array<string, mixed>
     */
    private function siteInput(Operation $operation, int $node, callable $signed): array
    {
        $plan = self::plan($operation);
        $publish = ValidatedData::object($plan['publish']);
        $input = $this->create->siteInput([...$publish, 'url_lines' => ValidatedData::object($plan['app_url'])['url_lines']], $node, $signed);

        return [...$input, 'leaves' => self::leaves($plan, RouteExecutionProof::objects($input['leaves']), ValidatedData::integerList($publish['leaf_indexes']))];
    }

    /**
     * One Node's leaves that wait for the cutover: `route.publish_site` with those leaves alone, no Caddyfile and no reload.
     *
     * @param  callable(int): string  $signed
     * @return array<string, mixed>
     */
    private function lateInput(Operation $operation, int $node, callable $signed): array
    {
        $plan = self::plan($operation);
        $input = $this->create->siteInput([...ValidatedData::object($plan['publish']), 'acl' => [], 'links' => [], 'php_files' => [], 'php_tests' => [], 'php_reloads' => [],
            'caddy' => [], 'url_lines' => [], 'trust' => []], $node, $signed);

        return [...$input, 'leaves' => self::leaves($plan, RouteExecutionProof::objects($input['leaves']), ValidatedData::integerList(ValidatedData::object($plan['retire'])['leaf_indexes']))];
    }

    /**
     * The site input's leaves that belong to the given plan indexes, matched by scope and version.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $leaves
     * @param  list<int>  $indexes
     * @return list<array<string, mixed>>
     */
    private static function leaves(array $plan, array $leaves, array $indexes): array
    {
        $all = RouteExecutionProof::objects($plan['leaves']);
        $wanted = array_map(static fn (int $index): string => ValidatedData::string($all[$index]['scope']).'/'.ValidatedData::string($all[$index]['target']), $indexes);

        return array_values(array_filter($leaves, static fn (array $leaf): bool => in_array(ValidatedData::string($leaf['scope']).'/versions/'.ValidatedData::string($leaf['version']), $wanted, true)));
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * A VPN Node's records and catalog with the new name and without the old one, guarded by the digests captured at admission.
     *
     * @return array{records: array<string, mixed>, catalog: array<string, mixed>}
     */
    private function dnsInput(Operation $operation, int $index): array
    {
        $row = RouteExecutionProof::objects(self::plan($operation)['dns'])[$index] ?? throw new LogicException("No DNS row {$index}.");
        $guard = RouteDestroyEngineFlow::guard($operation, ValidatedData::integer($row['node_id']));

        return ['records' => ['blob' => ['content' => ValidatedData::string($row['records'])], 'from_sha256' => RouteDestroyEngineFlow::digest($guard, 'records')],
            'catalog' => ['blob' => ['content' => ValidatedData::string($row['catalog'])], 'from_sha256' => RouteDestroyEngineFlow::digest($guard, 'catalog')]];
    }

    /**
     * One Node's withdrawal of the old site: the lasting Caddyfile over the prepared one this run published, and the old leaves.
     *
     * @return array<string, mixed>
     */
    private function withdrawInput(Operation $operation, int $node): array
    {
        $plan = self::plan($operation);
        $mine = static fn (mixed $rows): array => array_values(array_filter(RouteExecutionProof::objects($rows),
            static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node));
        $nodes = is_array($plan['nodes'] ?? null) ? $plan['nodes'] : [];
        $account = ValidatedData::object($nodes[$node] ?? [])['account'] ?? null;
        $lasting = $mine(ValidatedData::object($plan['retire'])['caddy']);
        $prepared = $mine(ValidatedData::object($plan['publish'])['caddy']);
        if (! is_string($account) || preg_match(RouteEngineFlow::Name, $account) !== 1 || $lasting === [] || $prepared === []) {
            throw new EngineUnsupported("Node {$node} withdraws the old site without a published Caddyfile.");
        }
        $leaves = [];
        foreach ($mine($plan['cleanup']) as $row) {
            $leaf = ValidatedData::object($row['leaf']);
            if (ValidatedData::string($leaf['user']) !== $account || preg_match(RouteEngineFlow::Scope, ValidatedData::string($leaf['scope'])) !== 1) {
                throw new EngineUnsupported('A leaf outside the account or scope shape runs on Swamp.');
            }
            $leaves[] = ValidatedData::string($leaf['scope']);
        }
        $domain = ValidatedData::string(Route::query()->whereKey(ValidatedData::integer(self::intent($operation)['route_id']))->value('domain') ?? '');
        if (preg_match(RouteEngineFlow::Domain, $domain) !== 1) {
            throw new EngineUnsupported('An old domain outside the executor\'s shape runs on Swamp.');
        }

        return ['account' => $account, 'domains' => [$domain], 'leaves' => array_values(array_unique($leaves)), 'php' => [],
            'caddy' => ['from_sha256' => hash('sha256', ValidatedData::string(end($prepared)['content'])),
                'content' => ['blob' => ['content' => ValidatedData::string(end($lasting)['content'])]]]];
    }

    /** The old Route's withdrawal as route.destroy's Gateway steps read it: its name, for the grace and the sibling check. */
    private function old(Operation $operation): Operation
    {
        $domain = Route::query()->whereKey(ValidatedData::integer(self::intent($operation)['route_id']))->value('domain');

        return (new Operation)->forceFill(['id' => $operation->id, 'kind' => 'route.destroy',
            'input' => ['route_removal' => ['routes' => [['route_id' => self::intent($operation)['route_id'], 'domain' => $domain, 'replacement_of' => null]]]]]);
    }

    /** @return array<string, mixed> */
    private static function intent(Operation $operation): array
    {
        return ValidatedData::object($operation->input['route_update'] ?? []);
    }

    /** @return array<string, mixed> */
    private static function plan(Operation $operation): array
    {
        return ValidatedData::object(self::intent($operation)['plan'] ?? []);
    }

    public function terminalStatuses(string $action): array
    {
        return ReceiptBoundDefinition::Terminal[$action] ?? ['done', 'failed'];
    }

    public function terminalFailure(string $action, string $code): bool
    {
        return ! str_starts_with($code, 'blob.');
    }

    /**
     * The steps of a retry after the cutover: each Node of the failed run's withdrawal, from that run's own plan, whose prepared
     * Caddyfile is what the Node still serves.
     *
     * @return list<StepSpec>
     */
    private function resumeSteps(Operation $operation): array
    {
        $earlier = self::resumed($operation) ?? throw new EngineUnsupported('A retry without a recorded cutover runs on Swamp.');
        $plan = self::plan($earlier);
        $steps = [];
        $after = [];
        // The grace runs again from the recorded DNS receipt: it waits only what is left of it.
        if (RouteExecutionProof::objects($plan['dns']) !== []) {
            $steps[] = new StepSpec('grace', 0, 'route.dns_grace', 'gateway', budgetMs: 60_000);
            $after = ['grace'];
        }
        $late = [];
        foreach (ValidatedData::integerList(ValidatedData::object($plan['retire'])['leaf_indexes']) as $index) {
            $late[ValidatedData::integer(RouteExecutionProof::objects($plan['leaves'])[$index]['node_id'])] = true;
        }
        foreach (array_keys($late) as $node) {
            // The late leaves again, from the run's own signatures: a repeat places the same files (`applied: 0`).
            RouteEngineFlow::fits($this->lateInput($earlier, $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n"));
            $steps[] = new StepSpec("late-{$node}", $node, 'route.publish_site', (string) $node, $after, budgetMs: 300_000, resendMs: 30_000);
        }
        foreach (array_values(array_unique(ValidatedData::integerList($plan['retire_nodes']))) as $node) {
            // The final Caddyfile placed and reloaded again, then read back: success needs its digest live.
            RouteEngineFlow::fits($this->withdrawInput($earlier, $node));
            $steps[] = new StepSpec("withdraw-{$node}", $node, 'route.withdraw_site', (string) $node, isset($late[$node]) ? [...$after, "late-{$node}"] : $after,
                budgetMs: 600_000, resendMs: 30_000);
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', ["withdraw-{$node}"], budgetMs: 60_000);
        }

        return $steps;
    }

    /** The recorded steps of a resumed run: its DNS receipts time the grace, and its signatures the late leaves. */
    private static function recorded(Operation $earlier): EngineRun
    {
        $steps = [];
        foreach (OperationEngineStep::query()->where('operation_id', $earlier->id)->get() as $step) {
            $steps[$step->name] = $step;
        }

        return new EngineRun($earlier->id, $steps, 0, null);
    }

    /**
     * The failed update whose cutover this retry resumes: the old Route retiring and still naming its replacement, the replacement
     * active and authoritative. Null when the request is not such a retry.
     */
    private static function resumed(Operation $operation): ?Operation
    {
        $intent = self::intent($operation);
        if ((ValidatedData::object($intent['before'])['status'] ?? null) === RouteStatus::Active->value) {
            return null;
        }
        $source = Route::query()->find(ValidatedData::integer($intent['route_id']));
        $selected = Route::query()->find(ValidatedData::integer($intent['result_route_id']));
        if ($source?->status !== RouteStatus::Retiring || $selected === null || $source->replaced_by_route_id !== $selected->id
            || $selected->status !== RouteStatus::Active || $selected->replaces_route_id !== null) {
            return null;
        }

        // The run that recorded the cutover holds the original prepared and final Caddyfiles; later failed retries do not.
        return Operation::query()->where('kind', 'route.update')->where('target_type', 'route')->where('target_id', $source->id)
            ->where('status', 'failed')->where('id', '<', $operation->id)->orderBy('id')->get()
            ->first(static fn (Operation $earlier): bool => (ValidatedData::object($earlier->input['route_update'] ?? [])['result_route_id'] ?? null) === $selected->id
                && OperationEngineStep::query()->where('operation_id', $earlier->id)->where('name', 'cutover')->where('status', 'done')->exists());
    }
}
