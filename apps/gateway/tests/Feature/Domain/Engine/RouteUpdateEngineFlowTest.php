<?php

declare(strict_types=1);

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** One phase of an admitted route_update plan on Node 3, in NativeRouteTargetPlan::phase's shape. */
function updatePhase(array $lists, array $indexes): array
{
    $empty = ['acl' => [], 'links' => [], 'php_files' => [], 'php_tests' => [], 'php_reloads' => [], 'caddy' => [], 'url_lines' => [], 'env_files' => [], 'lan' => []];

    return [...$empty, ...$lists, 'leaves' => updateLeaves(), 'nodes' => ['3' => ['account' => 'orbit']], 'leaf_indexes' => $indexes, 'trust' => [],
        'root_certificate' => "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n", 'site_nodes' => [3]];
}

function updateLeaves(): array
{
    return [
        ['node_id' => 3, 'scope' => 'app-instance-7', 'domain' => 'new.orbit', 'user' => 'orbit',
            'directory' => '/etc/caddy/orbit-certificates/app-instance-7/versions/0123456789abcdef', 'target' => 'versions/0123456789abcdef'],
        ['node_id' => 3, 'scope' => 'app-instance-7', 'domain' => 'new.orbit', 'user' => 'orbit',
            'directory' => '/etc/caddy/orbit-certificates/app-instance-7/versions/fedcba9876543210', 'target' => 'versions/fedcba9876543210'],
    ];
}

/** A private Route 9's domain change to replacement 10, as NativeRouteUpdateInput admits it. */
function updateIntent(array $plan = [], array $intent = []): array
{
    return [...['route_id' => 9, 'result_route_id' => 10, 'mode' => 'replace_domain', 'effectful' => true, 'public_activated' => false,
        'before' => ['publication' => 'private'], 'selected' => ['publication' => 'private', 'web_root' => null],
        'plan' => [...[
            'leaves' => updateLeaves(), 'leaf_indexes' => [0, 1], 'nodes' => ['3' => ['account' => 'orbit']],
            'probes' => [['node_id' => 3, 'address' => '10.44.0.2', 'domain' => 'new.orbit']], 'dns' => [], 'ingress' => [], 'ingress_ports' => [],
            'publish' => updatePhase(['caddy' => [['node_id' => 3, 'content' => "old.orbit {\n}\nnew.orbit {\n}\n"]]], [0]),
            'app_url' => updatePhase([], []), 'retire' => updatePhase(['caddy' => [['node_id' => 3, 'content' => "new.orbit {\n}\n"]]], [1]),
            'grace' => [], 'cleanup' => [], 'lan_absent' => [], 'retire_nodes' => [3],
        ], ...$plan]], ...$intent];
}

function updateOperation(array $intent, array $state = []): Operation
{
    return Operation::query()->forceCreate(['kind' => 'route.update', 'status' => OperationStatus::Running, 'target_type' => 'route', 'target_id' => $intent['route_id'],
        'node_id' => null, 'input' => ['route_update' => $intent], 'state' => $state]);
}

/** @return list<array{string, int, string, list<string>}> */
function updateSteps(Operation $operation): array
{
    return array_map(static fn ($step): array => [$step->name, $step->node, $step->action, $step->after], app(RouteUpdateEngineFlow::class)->steps($operation));
}

it('publishes the new site beside the old one, probes it, commits the cutover and only then writes the lasting Caddyfile', function (): void {
    expect(updateSteps(updateOperation(updateIntent())))->toBe([
        ['csr-0', 3, 'tls.ensure_csr', []],
        ['sign-0', 0, 'certificate.sign', ['csr-0']],
        ['csr-1', 3, 'tls.ensure_csr', []],
        ['sign-1', 0, 'certificate.sign', ['csr-1']],
        ['site-3', 3, 'route.publish_site', ['sign-0', 'sign-1']],
        ['probe-0', 0, 'route.probe', ['site-3']],
        ['cutover', 0, 'route.cutover', ['probe-0']],
        ['final-3', 3, 'route.publish_site', ['cutover']],
        ['sibling-3', 0, 'route.probe', ['final-3']],
    ]);
});

it('installs the early leaves with the publication and the late leaves with the lasting Caddyfile', function (): void {
    $operation = updateOperation(updateIntent());
    $run = new EngineRun($operation->id, [
        'sign-0' => (new OperationEngineStep)->forceFill(['output' => "-----BEGIN CERTIFICATE-----\nEARLY\n"]),
        'sign-1' => (new OperationEngineStep)->forceFill(['output' => "-----BEGIN CERTIFICATE-----\nLATE\n"]),
    ], 0, null);
    $site = app(RouteUpdateEngineFlow::class)->input($operation, 'site-3', $run);
    $final = app(RouteUpdateEngineFlow::class)->input($operation, 'final-3', $run);

    expect(array_column($site['leaves'], 'version'))->toBe(['0123456789abcdef'])
        ->and($site['caddy'])->toBe(['blob' => ['content' => "old.orbit {\n}\nnew.orbit {\n}\n"]])
        ->and(array_column($final['leaves'], 'version'))->toBe(['fedcba9876543210'])
        ->and($final['caddy'])->toBe(['blob' => ['content' => "new.orbit {\n}\n"]])
        ->and($site['url_lines'])->toBe([])->and($final['url_lines'])->toBe([]);
});

it('withdraws the old leaves through route.destroy\'s removal action after the lasting Caddyfile', function (): void {
    $operation = updateOperation(updateIntent(['cleanup' => [['node_id' => 3, 'leaf' => ['user' => 'orbit', 'scope' => 'app-instance-7-old']]]]));

    expect(array_slice(updateSteps($operation), -2))->toBe([
        ['withdraw-3', 3, RouteUpdateEngineFlow::RemovalAction, ['final-3']],
        ['sibling-3', 0, 'route.probe', ['withdraw-3']],
    ])->and(app(RouteUpdateEngineFlow::class)->input($operation, 'withdraw-3', new EngineRun($operation->id, [], 0, null)))
        ->toBe(['leaves' => [['user' => 'orbit', 'scope' => 'app-instance-7-old']]]);
});

it('leaves every URL write, public Route, edge, LAN rule and unguarded DNS move to Swamp', function (array $plan, array $intent): void {
    app(RouteUpdateEngineFlow::class)->steps(updateOperation(updateIntent($plan, $intent)));
})->throws(EngineUnsupported::class)->with([
    'APP_URL lines' => [['app_url' => updatePhase(['url_lines' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env', 'line' => 'APP_URL=https://new.orbit', 'owner' => 'orbit']]], [])], []],
    'a whole .env' => [['app_url' => updatePhase(['env_files' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env']]], [])], []],
    'a public Route' => [[], ['selected' => ['publication' => 'public', 'web_root' => null]]],
    'a publication change' => [[], ['mode' => 'publication']],
    'the Ingress edge' => [['ingress' => [['node_id' => 6]]], []],
    'a LAN rule' => [['lan_absent' => [['node_id' => 3]]], []],
    'private DNS before the guarded input lands' => [['dns' => [['node_id' => 1, 'records' => "address=/new.orbit/10.44.0.2\n", 'catalog' => "{}\n"]]], []],
]);

it('resumes an engine-started update after its cutover with only the post-cutover steps, from the stored plan and leaves', function (): void {
    $first = updateOperation(updateIntent(), ['swamp_run_id' => 'engine-1']);
    $first->forceFill(['status' => OperationStatus::Failed, 'state' => ['swamp_run_id' => 'engine-'.$first->id]])->save();
    foreach (['sign-1' => "-----BEGIN CERTIFICATE-----\nSTORED\n", 'cutover' => 'cutover 9 to 10'] as $name => $output) {
        OperationEngineStep::query()->forceCreate(['operation_id' => $first->id, 'name' => $name, 'node_id' => 0, 'action' => 'route.cutover', 'target' => 'gateway',
            'after' => [], 'budget_ms' => 30_000, 'resend_ms' => 5_000, 'status' => 'done', 'output' => $output]);
    }
    // The retry's own capture renders again; the resumed steps read the stored plan, not this one.
    $retry = updateOperation(updateIntent(['retire' => updatePhase(['caddy' => [['node_id' => 3, 'content' => "other\n"]]], [1])]));

    expect(updateSteps($retry))->toBe([
        ['final-3', 3, 'route.publish_site', []],
        ['sibling-3', 0, 'route.probe', ['final-3']],
    ]);
    $final = app(RouteUpdateEngineFlow::class)->input($retry, 'final-3', new EngineRun($retry->id, [], 0, null));
    expect($final['caddy'])->toBe(['blob' => ['content' => "new.orbit {\n}\n"]])
        ->and($final['leaves'][0]['certificate'])->toBe(['blob' => ['content' => "-----BEGIN CERTIFICATE-----\nSTORED\n"]]);
});

it('never mixes engines in one update chain', function (): void {
    $swamp = updateOperation(updateIntent(), ['swamp_run_id' => 'swamp-run-1']);
    $swamp->forceFill(['status' => OperationStatus::Failed])->save();
    expect(fn () => app(RouteUpdateEngineFlow::class)->steps(updateOperation(updateIntent())))->toThrow(EngineUnsupported::class);

    $swamp->forceFill(['status' => OperationStatus::Succeeded])->save();
    $engine = updateOperation(updateIntent());
    $engine->forceFill(['status' => OperationStatus::Failed, 'state' => ['swamp_run_id' => 'engine-'.$engine->id]])->save();
    expect(fn () => RouteUpdateEngineFlow::assertSwampMayRun(updateOperation(updateIntent())))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.update_engine_chain'));
});

it('labels every failure after the committed cutover as post-cutover, so the projection keeps the replacement authoritative', function (): void {
    expect(RouteUpdateEngineFlow::chartStep('site-3', false))->toBe('Install cert, publish site')
        ->and(RouteUpdateEngineFlow::chartStep('cutover', false))->toBe('Move private DNS')
        ->and(RouteUpdateEngineFlow::chartStep('final-3', true))->toBe('Withdraw the old site')
        ->and(RouteUpdateEngineFlow::chartStep('grace', true))->toBe('Wait the DNS grace')
        ->and(RouteUpdateEngineFlow::chartStep('site-3', true))->toBe('Withdraw the old site');
});

it('keeps an engine-owned update fenced until each sent step has a terminal receipt', function (): void {
    $operation = updateOperation(updateIntent());
    $operation->forceFill(['state' => ['swamp_run_id' => 'engine-'.$operation->id]]);

    expect(Engine::requiresReceipt($operation))->toBeTrue();
});
