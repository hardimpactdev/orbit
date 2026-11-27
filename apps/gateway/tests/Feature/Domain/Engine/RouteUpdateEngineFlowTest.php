<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\RouteMutationState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\EngineWait;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteUpdateInput;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Routes\RouteDestroyEngineFlow;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('leaves every URL write, public Route, edge, LAN rule and unguarded DNS move to Swamp', function (array $plan, array $intent): void {
    app(RouteUpdateEngineFlow::class)->steps(updateOperation(updateIntent($plan, $intent)));
})->throws(EngineUnsupported::class)->with([
    'APP_URL lines' => [['app_url' => updatePhase(['url_lines' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env', 'line' => 'APP_URL=https://new.orbit', 'owner' => 'orbit']]], [])], []],
    'a whole .env' => [['app_url' => updatePhase(['env_files' => [['node_id' => 3, 'path' => '/home/orbit/apps/shop/.env']]], [])], []],
    'a public Route' => [[], ['selected' => ['publication' => 'public', 'web_root' => null]]],
    'a publication change' => [[], ['mode' => 'publication']],
    'the Ingress edge' => [['ingress' => [['node_id' => 6]]], []],
    'a LAN rule' => [['lan_absent' => [['node_id' => 3]]], []],
    'private DNS without captured digests' => [['dns' => [['node_id' => 1, 'records' => "address=/new.orbit/10.44.0.2\n", 'catalog' => "{}\n"]]], []],
    'a leaf outside the account' => [['cleanup' => [['node_id' => 3, 'leaf' => ['user' => 'other', 'scope' => 'app-instance-7-hostname-change']]]], []],
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
    expect(fn () => RouteUpdateEngineFlow::assertChain(updateOperation(updateIntent()), false))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.update_engine_chain'));
});

it('keeps an update whose Swamp start was uncertain off the engine, though it was never bound', function (): void {
    $uncertain = updateOperation(updateIntent());
    $uncertain->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'swamp.start_uncertain'])->save();

    expect(RouteUpdateEngineFlow::owner(updateOperation(updateIntent())))->toBe('swamp')
        ->and(fn () => app(RouteUpdateEngineFlow::class)->steps(updateOperation(updateIntent())))->toThrow(EngineUnsupported::class);
});

it('resumes from the attempt that committed the cutover, past a later forward retry that failed', function (): void {
    $first = updateOperation(updateIntent());
    $first->forceFill(['status' => OperationStatus::Failed, 'state' => ['swamp_run_id' => 'engine-'.$first->id]])->save();
    OperationEngineStep::query()->forceCreate(['operation_id' => $first->id, 'name' => 'cutover', 'node_id' => 0, 'action' => 'route.cutover', 'target' => 'gateway',
        'after' => [], 'budget_ms' => 30_000, 'resend_ms' => 60_000, 'status' => 'done', 'output' => 'cutover 9 to 10']);
    $retry = updateOperation(updateIntent());
    $retry->forceFill(['status' => OperationStatus::Cancelled, 'state' => ['swamp_run_id' => 'engine-'.$retry->id]])->save();
    OperationEngineStep::query()->forceCreate(['operation_id' => $retry->id, 'name' => 'final-3', 'node_id' => 3, 'action' => 'route.publish_site', 'target' => '3',
        'after' => [], 'budget_ms' => 30_000, 'resend_ms' => 5_000, 'status' => 'failed']);

    $next = updateOperation(updateIntent());
    expect(RouteUpdateEngineFlow::cutoverOwner($next)?->id)->toBe($first->id)
        ->and(array_column(updateSteps($next), 0))->toBe(['final-3', 'sibling-3']);
});

it('probes any other site the Node serves, a proxy without an Instance target included, and fails a Node without an address', function (): void {
    $site = static fn (string $domain): DevelopmentSite => new DevelopmentSite(3, '10.44.0.2', 'proxy', '', '', null, $domain);
    $node = (new Node)->forceFill(['id' => 3, 'wireguard_ip' => '10.44.0.2']);

    expect(RouteUpdateEngineFlow::siblingProbe($node, [$site('new.orbit'), $site('old.orbit')], ['old.orbit', 'new.orbit']))->toBeNull()
        ->and(RouteUpdateEngineFlow::siblingProbe($node, [$site('new.orbit'), $site('proxy.orbit')], ['old.orbit', 'new.orbit']))
        ->toBe(['address' => '10.44.0.2', 'domain' => 'proxy.orbit'])
        ->and(fn () => RouteUpdateEngineFlow::siblingProbe($node->forceFill(['wireguard_ip' => null]), [$site('proxy.orbit')], ['new.orbit']))
        ->toThrow(RuntimeException::class);
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

it('waits the DNS grace from the stored DNS receipt as a resumable engine wait, and never shortens it on a retry', function (): void {
    Carbon::setTestNow('2026-11-27 04:00:00');
    $operation = updateOperation(updateIntent());
    $received = Engine::nowMs() - 10_000;
    $run = new EngineRun($operation->id, ['cutover' => (new OperationEngineStep)->forceFill(['name' => 'cutover', 'status' => 'done']),
        'dns-0' => (new OperationEngineStep)->forceFill(['name' => 'dns-0', 'status' => 'done', 'done_ms' => $received])], 0, null);
    $flow = app(RouteUpdateEngineFlow::class);

    expect($flow)->toBeInstanceOf(EngineWait::class)
        ->and($flow->waitUntilMs($operation, 'grace', $run))->toBe($received + 31_000)
        ->and($flow->waitUntilMs($operation, 'final-3', $run))->toBeNull();

    Carbon::setTestNow(Carbon::createFromTimestampMs($received + 31_000));
    expect($flow->waitUntilMs($operation, 'grace', $run))->toBe($received + 31_000)
        ->and($flow->local($operation, 'grace', $run))->toBe('waited 31000 ms after the DNS receipt');
    Carbon::setTestNow();
});

it('settles a retry\'s success after a post-cutover failure without making the acknowledged cutover again', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'app-dev', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/shop.git', 'default_branch' => 'main', 'root' => 'public']);
    $route = static fn (string $domain, array $extra = []): Route => Route::query()->create(['kind' => 'app', 'project_id' => $project->id,
        'node_id' => $node->id, 'provenance' => 'explicit', 'publication' => 'private', 'domain' => $domain, 'status' => 'pending', ...$extra]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'shop',
        'checkout_path' => '/home/orbit/apps/shop', 'status' => 'active', 'source_layout' => 'worktree', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $old = $route('old.shop.test');
    $old->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $old->update(['status' => 'active', 'sites_published' => true]);
    $new = $route('new.shop.test', ['replaces_route_id' => $old->id, 'replacement_step' => RouteReplacementStep::Reserved]);
    $new->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $old->update(['replaced_by_route_id' => $new->id]);
    $new->update(['status' => 'activating']);
    $intent = updateIntent([], ['route_id' => $old->id, 'result_route_id' => $new->id, 'analytics_changes' => []]);
    $settle = function (OperationStatus $status, ?string $step) use ($intent): void {
        $operation = updateOperation($intent);
        $operation->forceFill(['status' => $status, 'error_code' => $step === null ? null : 'route.withdraw_failed'])->save();
        app(NativeRouteUpdateProjection::class)->settle($operation, $step);
    };

    $settle(OperationStatus::Failed, 'Withdraw the old site');
    expect($old->refresh()->status->value)->toBe('retiring')->and($new->refresh()->status->value)->toBe('active')
        ->and($new->replaces_route_id)->toBeNull();

    $settle(OperationStatus::Succeeded, null);
    expect($new->refresh()->status->value)->toBe('active')
        ->and(Route::query()->find($old->id))->toBeNull();
});

it('fences the replacement Route of an open engine-run update against a removal, and frees it at the update\'s end', function (): void {
    $update = updateOperation(updateIntent());
    $replacement = (new Route)->forceFill(['id' => 10]);
    RouteUpdateEngineFlow::assertNotFenced($replacement);

    $update->forceFill(['state' => ['swamp_run_id' => 'engine-'.$update->id]])->save();
    expect(fn () => RouteUpdateEngineFlow::assertNotFenced($replacement))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.target_busy'));

    $update->forceFill(['status' => OperationStatus::Failed])->save();
    RouteUpdateEngineFlow::assertNotFenced($replacement);
});

it('keeps a chain on Swamp whose unbound uncertain start the expiry rewrote to a timeout', function (): void {
    $expired = updateOperation(updateIntent());
    $expired->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out'])->save();

    expect(RouteUpdateEngineFlow::owner(updateOperation(updateIntent())))->toBe('swamp');
});

it('refuses a record-only edit of the replacement an open engine update publishes', function (): void {
    $update = updateOperation(updateIntent());
    $update->forceFill(['state' => ['swamp_run_id' => 'engine-'.$update->id]])->save();
    $replacement = (new Route)->forceFill(['id' => 10, 'kind' => 'app']);

    expect(fn () => app(AdmitRouteOperationAction::class)->start('route.update', $replacement, ['mode' => 'publication']))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.target_busy'));
});

it('resumes a stored lasting Caddyfile only while the catalog still renders it for that Node, under the hold', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'app-dev', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    $current = app(NodeCaddyfileRenderer::class)->render($node)->content;
    $resume = function (string $lasting) use ($node): Operation {
        DB::table('route_publication_holds')->delete();
        Operation::query()->where('kind', 'route.update')->delete();
        $first = updateOperation(updateIntent(['retire' => [...updatePhase(['caddy' => [['node_id' => $node->id, 'content' => $lasting]]], [1]), 'site_nodes' => [$node->id]]]));
        $first->forceFill(['status' => OperationStatus::Failed, 'state' => ['swamp_run_id' => 'engine-'.$first->id]])->save();
        OperationEngineStep::query()->forceCreate(['operation_id' => $first->id, 'name' => 'cutover', 'node_id' => 0, 'action' => 'route.cutover', 'target' => 'gateway',
            'after' => [], 'budget_ms' => 30_000, 'resend_ms' => 60_000, 'status' => 'done', 'output' => 'cutover 9 to 10']);

        // The identical retry's own capture has no lasting row: the catalog already serves the replacement.
        return updateOperation(updateIntent(['retire' => [...updatePhase([], []), 'site_nodes' => []]]));
    };

    RouteUpdateEngineFlow::assertCurrent($resume($current));
    expect(fn () => RouteUpdateEngineFlow::assertCurrent($resume($current."sibling.orbit {\n}\n")))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_stale'));
});

it('refuses a first attempt whose Node gained a sibling Route between admission and bind, from the real catalog', function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'update-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $site = function (string $slug, string $domain) use ($node): Route {
        $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package',
            'repository_url' => "https://github.com/example/{$slug}.git", 'default_branch' => 'main', 'root' => 'public']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $slug,
            'checkout_path' => "/home/orbit/apps/{$slug}", 'status' => 'active', 'source_layout' => 'worktree', 'root' => 'public',
            'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id,
            'provenance' => 'explicit', 'publication' => 'private', 'domain' => $domain, 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);

        return $route->refresh();
    };
    $route = $site('shop', 'shop.fixture.test');
    $candidate = app(RouteMutationState::class)->reserve($route, 'store.fixture.test');
    $input = ['mode' => 'replace_domain', 'domain' => 'store.fixture.test', 'publication' => 'private', 'web_root' => null,
        'candidate_route_id' => $candidate->id, 'allow_generated' => false];
    $capture = app(NativeRouteUpdateInput::class)->capture($route->refresh()->load('targets.instance.node'), $input);
    $operation = Operation::query()->forceCreate(['kind' => 'route.update', 'status' => OperationStatus::Running, 'target_type' => 'route',
        'target_id' => $route->id, 'node_id' => null, 'input' => [...$input, 'route_update' => $capture['intent']], 'state' => []]);
    expect(RouteUpdateEngineFlow::plan($operation)['retire']['caddy'] ?? [])->not->toBe([]);

    RouteUpdateEngineFlow::assertCurrent($operation);
    $site('blog', 'blog.fixture.test');
    expect(fn () => RouteUpdateEngineFlow::assertCurrent($operation))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_stale'));
});

it('runs a real domain change of a Route without URL writes on the engine, withdrawing the staging leaf over the lasting Caddyfile', function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'update-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $site = function (string $slug, string $domain) use ($node): Route {
        $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package',
            'repository_url' => "https://github.com/example/{$slug}.git", 'default_branch' => 'main', 'root' => 'public']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $slug,
            'checkout_path' => "/home/orbit/apps/{$slug}", 'status' => 'active', 'source_layout' => 'worktree', 'root' => 'public',
            'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id,
            'provenance' => 'explicit', 'publication' => 'private', 'domain' => $domain, 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);

        return $route->refresh();
    };
    $route = $site('shop', 'shop.fixture.test');
    $candidate = app(RouteMutationState::class)->reserve($route, 'store.fixture.test');
    $input = ['mode' => 'replace_domain', 'domain' => 'store.fixture.test', 'publication' => 'private', 'web_root' => null,
        'candidate_route_id' => $candidate->id, 'allow_generated' => false];
    $capture = app(NativeRouteUpdateInput::class)->capture($route->refresh()->load('targets.instance.node'), $input);
    $operation = Operation::query()->forceCreate(['kind' => 'route.update', 'status' => OperationStatus::Running, 'target_type' => 'route',
        'target_id' => $route->id, 'node_id' => null, 'input' => [...$input, 'route_update' => $capture['intent']], 'state' => []]);
    $plan = RouteUpdateEngineFlow::plan($operation);
    $cleanup = $plan['cleanup'];
    expect($cleanup)->toHaveCount(1)->and($cleanup[0]['leaf']['scope'])->toEndWith('-hostname-change');

    $steps = updateSteps($operation);
    $names = array_column($steps, 0);
    $withdraw = "withdraw-{$node->id}";
    expect($names)->toContain('cutover', "final-{$node->id}", $withdraw, "sibling-{$node->id}")
        ->and($steps[array_search($withdraw, $names, true)])->toBe([$withdraw, $node->id, 'route.withdraw_site', ["final-{$node->id}"]]);

    $input = app(RouteUpdateEngineFlow::class)->input($operation, $withdraw, new EngineRun($operation->id, [], 0, null));
    $lasting = collect($plan['retire']['caddy'])->firstWhere('node_id', $node->id)['content'];
    expect($input)->toBe(['account' => 'orbit', 'domains' => ['shop.fixture.test'], 'leaves' => [$cleanup[0]['leaf']['scope']],
        'caddy' => ['from_sha256' => hash('sha256', $lasting), 'content' => ['blob' => ['content' => $lasting]]]])
        ->and($lasting)->not->toContain('shop.fixture.test');
});

it('moves private DNS with route.destroy\'s guarded publication, from the digests captured at admission', function (): void {
    $dns = [['node_id' => 1, 'records' => "address=/new.orbit/10.44.0.2\n", 'catalog' => "{}\n"]];
    $intent = [...updateIntent(['dns' => $dns, 'grace' => [['seconds' => 31]]]), 'dns_guards' => [1 => ['records' => str_repeat('a', 64), 'catalog' => str_repeat('b', 64)]]];
    $operation = updateOperation($intent);

    $steps = app(RouteUpdateEngineFlow::class)->steps($operation);
    $dnsStep = collect($steps)->firstWhere('name', 'dns-0');
    expect($dnsStep->action)->toBe('dns.publish_records')->and($dnsStep->node)->toBe(1)
        ->and($dnsStep->features)->toBe([RouteDestroyEngineFlow::GuardedDns])
        ->and(collect($steps)->firstWhere('name', 'cutover')->after)->toContain('dns-0')
        ->and(collect($steps)->firstWhere('name', 'grace')->after)->toBe(['cutover'])
        ->and(app(RouteUpdateEngineFlow::class)->input($operation, 'dns-0', new EngineRun($operation->id, [], 0, null)))->toBe([
            'records' => ['blob' => ['content' => "address=/new.orbit/10.44.0.2\n"], 'from_sha256' => str_repeat('a', 64)],
            'catalog' => ['blob' => ['content' => "{}\n"], 'from_sha256' => str_repeat('b', 64)]]);
});
