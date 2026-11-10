<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsTrackingSteps;
use App\Domain\Routes\NativeClusterRouterSteps;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** @return array{revision:string,listen:list<string>,tlds:list<array{name:string,address:string}>,records:list<array{name:string,address:string,pair:bool}>} */
function plannerDns(string $revision, string $gateway = '10.44.0.2'): array
{
    return ['revision' => $revision, 'listen' => ['10.44.0.1'], 'tlds' => [['name' => 'test', 'address' => '10.44.0.7']],
        'records' => [['name' => 'gateway.orbit', 'address' => $gateway, 'pair' => true]]];
}

/** @param array{revision:string,listen:list<string>,tlds:list<array{name:string,address:string}>,records:list<array{name:string,address:string,pair:bool}>} $before */
function createPlanDns(array $before, array $after): array
{
    $state = ['caddy' => null, 'php' => null];
    $lifecycle = ['effectful' => true, 'public_activated' => false, 'awake' => [], 'workloads' => [], 'preparation' => ['certificates' => [], 'lan' => []],
        'renders' => ['5' => ['caddy' => null, 'php' => null, 'dns' => $after]],
        'route_mutations' => ['artifacts' => ['dns' => [$before, $after]],
            'forward' => ['publications' => [['node_id' => 5, 'serves_routes' => false,
                'before' => [...$state, 'dns' => 0], 'after' => [...$state, 'dns' => 1]]],
                'certificates' => [], 'lan' => [], 'ingress' => [], 'url_writes' => []]]];

    return app(NativeRouteCreatePlan::class)->build(new Route(['domain' => 'hauzer.app']), $lifecycle)['dns'];
}

/** @return array<string,mixed> */
function clusterRouterDns(?array $before, array $after): array
{
    $input = ['cluster_router' => ['old_nodes' => [], 'candidate' => null],
        'renders' => ['5' => ['before' => null, 'prepared' => null, 'caddy' => null, 'php' => null, 'dns' => $after, 'dns_before' => $before, 'scopes' => []]],
        'route_mutations' => ['forward' => ['prepare_certificates' => [], 'lan' => [], 'retire_lan' => [], 'cleanup' => [], 'routes' => []]]];
    $steps = app(NativeClusterRouterSteps::class)->build($input, false);

    return ['dns' => $steps['dns'], 'restores_dns' => isset($steps['restore_dns_plan'])];
}

/** @return list<array<string,mixed>> */
function analyticsDns(?array $before, ?array $after): array
{
    return app(AnalyticsTrackingSteps::class)->build([], [['node_id' => 5, 'before' => $before, 'after' => $after]], [], [], [], true)['dns'];
}

describe('Route create plan', function (): void {
    it('plans the complete state for the vpn Node when a record changed', function (): void {
        $after = plannerDns('2', '10.44.0.3');

        expect(createPlanDns(plannerDns('1'), $after))->toBe([['node_id' => 5, 'dns' => $after]]);
    });

    it('plans nothing when only the revision differs, as for a domain inside a TLD', function (): void {
        expect(createPlanDns(plannerDns('1'), plannerDns('2')))->toBe([]);
    });
});

describe('Cluster Router steps', function (): void {
    it('selects the new Router without replaying a captured DNS rollback', function (): void {
        $before = plannerDns('10');
        $after = plannerDns('20', '10.44.0.3');

        $plan = clusterRouterDns($before, $after);

        expect($plan['dns'])->toBe([['node_id' => 5, 'dns' => $after]])
            ->and($plan['restores_dns'])->toBeFalse();
    });

    it('plans no DNS when the Router change leaves the records as they are', function (): void {
        expect(clusterRouterDns(plannerDns('10'), plannerDns('20')))->toBe(['dns' => [], 'restores_dns' => false]);
    });
});

describe('Analytics tracking steps', function (): void {
    it('publishes the complete state of a changed private DNS', function (): void {
        $after = plannerDns('2', '10.44.0.3');

        expect(analyticsDns(plannerDns('1'), $after))->toBe([['node_id' => 5, 'dns' => $after]]);
    });

    it('publishes nothing for an unchanged render or a Node without private DNS', function (): void {
        expect(analyticsDns(plannerDns('1'), plannerDns('2')))->toBe([])
            ->and(analyticsDns(plannerDns('1'), null))->toBe([]);
    });
});
