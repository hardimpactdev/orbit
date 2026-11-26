<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Domain\Routes\RouteEngineFlow;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RoutePublication;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * `instance:destroy --force` of a development clone on the state machine (docs/cli/instance/container/destroy-engine.mmd). A clone
 * that owns private Routes withdraws them first, with route.destroy's primitives and no DNS grace (a destroyed name has no new
 * target): `dns-<node>` moves each VPN Node's answers, `withdraw-<node>` takes the sites and leaves off each serving Node,
 * `sibling-<node>` checks another Route there still answers, and `release` records the withdrawal on the removal and frees the
 * Route Nodes, as the Swamp run's release step does. Then `remove` deletes the container.
 */
final readonly class InstanceDestroyEngineFlow implements EngineDefinition
{
    public const string Kind = 'instance.destroy';

    private const string Remove = 'remove';

    private const string Release = 'release';

    public function __construct(private DevelopmentInstanceDestroyProjection $projection, private RouteDestroyEngineFlow $routes) {}

    public function steps(Operation $operation): array
    {
        $container = self::container($operation);
        $remove = static fn (array $after): StepSpec => new StepSpec(self::Remove, ValidatedData::integer($operation->node_id), self::Kind,
            (string) ValidatedData::integer($container['instance_id']), ValidatedData::stringList($after), budgetMs: 600_000, resendMs: 15_000);
        $withdrawal = self::withdrawal($operation);
        if ($withdrawal === null) {
            return [$remove([])];
        }
        $steps = $dns = $done = [];
        foreach ($withdrawal['nodes'] as $node => $row) {
            if (isset($row['dns'])) {
                self::dnsInput($withdrawal, $node);
                $steps[] = $dns[] = new StepSpec("dns-{$node}", $node, 'dns.publish_records', (string) $node, budgetMs: 120_000, resendMs: 20_000,
                    features: [RouteDestroyEngineFlow::GuardedDns]);
            }
        }
        $after = array_map(static fn (StepSpec $step): string => $step->name, $dns);
        foreach ($withdrawal['serving'] as $node) {
            self::withdrawInput($withdrawal, $node);
            $steps[] = new StepSpec("withdraw-{$node}", $node, 'route.withdraw_site', (string) $node, $after, budgetMs: 600_000, resendMs: 30_000);
            $steps[] = new StepSpec("sibling-{$node}", 0, 'route.probe', 'gateway', ["withdraw-{$node}"], budgetMs: 60_000);
            $done[] = "sibling-{$node}";
        }
        $steps[] = new StepSpec(self::Release, 0, 'route.release', 'gateway', [...$after, ...$done], budgetMs: 30_000);
        $steps[] = $remove([self::Release]);

        return $steps;
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        [$kind] = explode('-', $step, 2);
        $node = (int) (explode('-', $step, 2)[1] ?? 0);
        if ($kind === self::Remove) {
            return Arr::only(self::container($operation), ['project', 'name', 'pool', 'acl', 'inner_volume']);
        }
        $withdrawal = self::withdrawal($operation) ?? throw new LogicException("Step [{$step}] has no Route withdrawal.");

        return match ($kind) {
            'dns' => self::dnsInput($withdrawal, $node),
            'withdraw' => self::withdrawInput($withdrawal, $node),
            default => throw new LogicException("Unknown destroy step [{$step}]."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        [$kind] = explode('-', $step, 2);
        $withdrawal = self::withdrawal($operation) ?? throw new LogicException("Step [{$step}] has no Route withdrawal.");

        return match ($kind) {
            'sibling' => $this->routes->probeSibling((int) explode('-', $step, 2)[1], $withdrawal['domains']),
            self::Release => DB::transaction(static function () use ($operation): string {
                $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
                app(InstanceDestroyRouteWithdrawal::class)->record($locked);
                app(RoutePublicationHolds::class)->releaseWithdrawn($locked);

                return 'route withdrawn, Route Nodes released';
            }),
            default => throw new LogicException("Unknown destroy step [{$step}]."),
        };
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $this->projection->applyEngine($operation, $run);
    }

    /** @return array<string, mixed> */
    private static function container(Operation $operation): array
    {
        $input = $operation->input;
        $container = ValidatedData::object($input['container_destroy'] ?? []);
        $plan = ValidatedData::object($input['direct_destroy'] ?? []);
        $runtime = ValidatedData::object($plan['runtime'] ?? []);
        $database = ValidatedData::object($plan['database'] ?? []);
        $teardown = $plan['teardown'] ?? null;
        $members = InstanceLifecycleInput::members($input);
        $id = $operation->target_id;
        $name = 'instance-'.$id;
        $cloneSource = $container['clone_source_instance_id'] ?? null;

        if ($operation->target_type !== 'instance' || $id < 1 || ($input['instance_id'] ?? null) !== $id || ($operation->node_id ?? 0) < 1
            || ($input['node_id'] ?? null) !== $operation->node_id || ($plan['node_id'] ?? null) !== $operation->node_id
            || ($input['environment'] ?? null) !== 'development' || ($input['force'] ?? false) !== true
            || ($input['name'] ?? DefaultInstanceName::Name) === DefaultInstanceName::Name
            || ! is_int($cloneSource) || $cloneSource < 1 || $cloneSource === $id
            || isset($input['after_operation_id']) || isset($container['ci_hold'])
            || count($members) !== 1 || ($members[0]['instance_id'] ?? null) !== $id
            || ! is_array($input['additional_route_ids'] ?? null) || ($plan['routes'] ?? null) !== []
            || ($members[0]['run_teardown'] ?? null) !== false || ! is_array($teardown) || count($teardown) !== 1 || ! is_array($teardown[0] ?? null)
            || ($teardown[0]['instance_id'] ?? null) !== $id || ($teardown[0]['steps'] ?? null) !== []
            || ($runtime['processes'] ?? null) !== [] || ($runtime['schedules'] ?? null) !== []
            || ($database['connection_ids'] ?? null) !== [] || ($database['servers'] ?? null) !== [] || ($database['sqlite'] ?? null) !== []) {
            throw new EngineUnsupported('Only a forced development clone removal without other teardown runs on the engine.');
        }

        if (($container['instance_id'] ?? null) !== $id || ($container['name'] ?? null) !== $name || ($container['acl'] ?? null) !== $name
            || ! in_array($container['project'] ?? null, [InstanceHostIntent::Project, InstancePlacement::NestedProject], true)
            || ($container['pool'] ?? null) !== InstanceHostIntent::Pool
            || ($container['mirror'] ?? null) !== "/var/lib/orbit/instance-repositories/{$id}.git"
            || ($container['firewall_prefix'] ?? null) !== "orbit:instance-{$id}-"
            || ($container['asleep_marker'] ?? null) !== RuntimeHibernation::asleepPath(RuntimeHibernation::key($id))
            || (array_key_exists('inner_volume', $container) && $container['inner_volume'] !== NestedFleetInnerVolume::name($name))) {
            throw new EngineUnsupported('The saved clone identity is outside the signed removal contract.');
        }

        return $container;
    }

    /**
     * The clone's Route withdrawal, or null when it owns no Route or a retry already withdrew it. Only private development Routes
     * whose whole after-state the admission rendered (`route_engine`, RouteFileGuards::withdrawal) run here; LAN rules, the Ingress
     * edge or a PHP version whose pool empties throw [EngineUnsupported].
     *
     * @return array{nodes: array<int, array<string, mixed>>, serving: list<int>, domains: list<string>, accounts: array<int, string>, leaves: array<int, list<string>>}|null
     */
    private static function withdrawal(Operation $operation): ?array
    {
        $input = $operation->input;
        $members = InstanceLifecycleInput::members($input);
        if (($input['route_withdrawn'] ?? false) === true || (! isset($members[0]['route_id']) && ($input['additional_route_ids'] ?? []) === [])) {
            return null;
        }
        $engine = $input['route_engine'] ?? null;
        $forward = ValidatedData::object(ValidatedData::object(ValidatedData::object($input['native_destroy'] ?? [])['route_mutations'] ?? [])['forward'] ?? []);
        if (! is_array($engine) || $engine === []) {
            throw new EngineUnsupported('A Route withdrawal without its rendered after-state runs on Swamp.');
        }
        foreach (['lan', 'ingress'] as $list) {
            if (($forward[$list] ?? []) !== []) {
                throw new EngineUnsupported("A Route withdrawal with {$list} runs on Swamp.");
            }
        }
        foreach (['retire_lan', 'retire_ingress'] as $list) {
            foreach (RouteExecutionProof::objects($forward[$list] ?? []) as $row) {
                if (RouteExecutionProof::objects($row['rules'] ?? []) !== []) {
                    throw new EngineUnsupported("A Route withdrawal with {$list} runs on Swamp.");
                }
            }
        }
        $domains = [];
        $ids = array_map(static fn (array $route): int => ValidatedData::integer($route['route_id']), RouteExecutionProof::objects($forward['routes'] ?? []));
        if (Route::query()->whereKey($ids)->where('publication', '!=', RoutePublication::Private)->exists()) {
            throw new EngineUnsupported('A public Route\'s withdrawal runs on Swamp.');
        }
        foreach (RouteExecutionProof::objects($forward['routes'] ?? []) as $route) {
            $domain = ValidatedData::string($route['domain']);
            $domains[] = preg_match(RouteEngineFlow::Domain, $domain) === 1 ? $domain : throw new EngineUnsupported('A domain outside the executor\'s shape runs on Swamp.');
        }
        $accounts = $leaves = [];
        foreach (RouteExecutionProof::objects($forward['publications'] ?? []) as $publication) {
            $accounts[ValidatedData::integer($publication['node_id'])] = ValidatedData::string($publication['user']);
        }
        foreach (RouteExecutionProof::objects($forward['cleanup'] ?? []) as $leaf) {
            $leaves[ValidatedData::integer($leaf['node_id'])][] = ValidatedData::string($leaf['scope']);
        }
        $nodes = $serving = [];
        foreach ($engine as $node => $row) {
            $row = ValidatedData::object($row);
            if (($row['pool_removed'] ?? false) === true) {
                throw new EngineUnsupported('A PHP version whose pool empties runs on Swamp.');
            }
            $nodes[(int) $node] = $row;
            if (isset($row['caddy']) && (($row['pools'] ?? []) !== [] || isset($leaves[(int) $node]) || ValidatedData::object($row['caddy'])['from_sha256'] !== hash('sha256', ValidatedData::string(ValidatedData::object($row['caddy'])['content'])))) {
                $serving[] = (int) $node;
            }
        }
        ksort($nodes);
        sort($serving);
        if ($domains === [] || array_diff(array_keys($leaves), $serving) !== []) {
            throw new EngineUnsupported('A Route withdrawal outside the rendered Nodes runs on Swamp.');
        }

        return ['nodes' => $nodes, 'serving' => $serving, 'domains' => $domains, 'accounts' => $accounts, 'leaves' => $leaves];
    }

    /**
     * @param  array{nodes: array<int, array<string, mixed>>, serving: list<int>, domains: list<string>, accounts: array<int, string>, leaves: array<int, list<string>>}  $withdrawal
     * @return array<string, mixed>
     */
    private static function dnsInput(array $withdrawal, int $node): array
    {
        $dns = ValidatedData::object($withdrawal['nodes'][$node]['dns'] ?? null);
        $file = static fn (string $name): array => ['blob' => ['content' => ValidatedData::string(ValidatedData::object($dns[$name])['content'])],
            'from_sha256' => RouteDestroyEngineFlow::digest(['digest' => ValidatedData::object($dns[$name])['from_sha256']], 'digest')];

        return ['records' => $file('records'), 'catalog' => $file('catalog')];
    }

    /**
     * @param  array{nodes: array<int, array<string, mixed>>, serving: list<int>, domains: list<string>, accounts: array<int, string>, leaves: array<int, list<string>>}  $withdrawal
     * @return array<string, mixed>
     */
    private static function withdrawInput(array $withdrawal, int $node): array
    {
        $row = $withdrawal['nodes'][$node];
        $account = $withdrawal['accounts'][$node] ?? null;
        if (! is_string($account) || preg_match(RouteEngineFlow::Name, $account) !== 1) {
            throw new EngineUnsupported("Node {$node} has no account in the executor's shape.");
        }
        $leaves = $withdrawal['leaves'][$node] ?? [];
        foreach ($leaves as $scope) {
            if (preg_match(RouteEngineFlow::Scope, $scope) !== 1) {
                throw new EngineUnsupported('A leaf outside the scope shape runs on Swamp.');
            }
        }
        $caddy = ValidatedData::object($row['caddy']);

        return ['account' => $account, 'domains' => $withdrawal['domains'], 'leaves' => array_values(array_unique($leaves)),
            'php' => array_map(static fn (array $pool): array => ['version' => ValidatedData::string($pool['version']), 'user' => null,
                'content' => ['blob' => ['content' => ValidatedData::string($pool['content'])]]], RouteExecutionProof::objects($row['pools'] ?? [])),
            'caddy' => ['from_sha256' => RouteDestroyEngineFlow::digest($caddy, 'from_sha256'), 'content' => ['blob' => ['content' => ValidatedData::string($caddy['content'])]]]];
    }
}
