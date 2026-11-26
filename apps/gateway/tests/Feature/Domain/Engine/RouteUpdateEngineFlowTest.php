<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\RouteMutationState;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['orbit.engine.kinds' => ['route.update']]);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n");
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key']));
    $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'workload', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key']));
    foreach (['app-dev', 'vpn'] as $role) {
        $this->node->roles()->create(['role' => $role, 'status' => 'active']);
    }
    $project = Project::query()->create(['name' => 'shop', 'slug' => 'shop', 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/example/fixture.git', 'default_branch' => 'main', 'root' => 'public', 'php_version' => '8.5']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'shop',
        'checkout_path' => '/apps/shop', 'selected_php_version' => '8.5', 'status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false]);
    $this->route = Route::query()->create(['kind' => 'app', 'node_id' => $this->node->id, 'project_id' => $project->id,
        'domain' => 'shop.outside.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
});

function updatedRoute(Route $route, string $domain = 'renamed.outside.test'): Operation
{
    return app(AdmitRouteOperationAction::class)->update($route, new UpdateRouteData(true, $domain, false, null));
}

it('publishes and probes the new name before it moves DNS, records the cutover, then withdraws the old site', function (): void {
    $before = app(NativeRoutePublicationInput::class)->snapshot($this->node, app(DevelopmentSiteRepository::class)->all(), true);
    $operation = updatedRoute($this->route);
    $node = $this->node->id;
    $steps = array_map(static fn ($step): array => [$step->name, $step->action, $step->after], app(RouteUpdateEngineFlow::class)->steps($operation));

    expect($steps)->toBe([
        ['csr-0', 'tls.ensure_csr', []],
        ['sign-0', 'certificate.sign', ['csr-0']],
        ['csr-1', 'tls.ensure_csr', []],
        ['sign-1', 'certificate.sign', ['csr-1']],
        ["site-{$node}", 'route.publish_site', ['sign-0', 'sign-1']],
        ['probe-0', 'route.probe', ["site-{$node}"]],
        ['dns-0', 'dns.publish_records', ['probe-0']],
        ['cutover', 'route.cutover', ['probe-0', 'dns-0']],
        ['grace', 'route.dns_grace', ['cutover']],
        ["late-{$node}", 'route.publish_site', ['grace']],
        ["withdraw-{$node}", 'route.withdraw_site', ['grace', "late-{$node}"]],
        ["sibling-{$node}", 'route.probe', ["withdraw-{$node}"]],
    ])->and($operation->input['route_guards'][$node])->toMatchArray(['records' => hash('sha256', $before['dns']['records']), 'catalog' => hash('sha256', $before['dns']['catalog'])]);

    $flow = app(RouteUpdateEngineFlow::class);
    $run = new EngineRun($operation->id, ['sign-0' => (new OperationEngineStep)->forceFill(['output' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n"])], 0, null);
    $run = new EngineRun($operation->id, ['sign-0' => $run->steps['sign-0'], 'sign-1' => (new OperationEngineStep)->forceFill(['output' => "-----BEGIN CERTIFICATE-----\nLATE\n-----END CERTIFICATE-----\n"])], 0, null);
    $site = $flow->input($operation, "site-{$node}", $run);
    $late = $flow->input($operation, "late-{$node}", $run);
    $leaves = $operation->input['route_update']['plan']['leaves'];
    expect(array_column($site['leaves'], 'scope'))->toBe([$leaves[0]['scope']])
        ->and(array_column($late['leaves'], 'certificate'))->toBe([['blob' => ['content' => "-----BEGIN CERTIFICATE-----\nLATE\n-----END CERTIFICATE-----\n"]]])
        ->and($late['caddy'])->toBeNull()->and($late['acl'])->toBe([]);
    $withdraw = $flow->input($operation, "withdraw-{$node}", $run);
    expect(array_column($site['url_lines'], 'url'))->each->toBe('https://renamed.outside.test')
        ->and(base64_encode((string) $site['caddy']['blob']['content']))->not->toBe('')
        ->and($withdraw['domains'])->toBe(['shop.outside.test'])
        ->and($withdraw['caddy']['from_sha256'])->toBe(hash('sha256', $site['caddy']['blob']['content']))
        ->and($flow->input($operation, 'dns-0', $run)['records']['from_sha256'])->toBe(hash('sha256', $before['dns']['records']));
});

it('records the replacement authoritative and the old Route retiring at the cutover, and again on a repeat', function (): void {
    $operation = updatedRoute($this->route);
    $flow = app(RouteUpdateEngineFlow::class);
    $replacement = Route::query()->findOrFail($operation->input['route_update']['result_route_id']);

    $flow->local($operation, 'cutover', new EngineRun($operation->id, [], 0, null));
    $flow->local($operation, 'cutover', new EngineRun($operation->id, [], 0, null));

    expect($this->route->refresh()->status)->toBe(RouteStatus::Retiring)
        ->and($replacement->refresh()->status)->toBe(RouteStatus::Activating);
});

it('settles a failure after the cutover forward, and one before it on the old Route', function (bool $cut): void {
    $operation = updatedRoute($this->route);
    $operation->update(['status' => 'running', 'state' => [...$operation->state, 'swamp_run_id' => EngineRun::runIdOf($operation->id)]]);
    $flow = app(RouteUpdateEngineFlow::class);
    $replacement = Route::query()->findOrFail($operation->input['route_update']['result_route_id']);
    $node = $this->node->id;
    if ($cut) {
        $flow->local($operation, 'cutover', new EngineRun($operation->id, [], 0, null));
    }
    $failed = (new OperationEngineStep)->forceFill(['name' => $cut ? "withdraw-{$node}" : 'probe-0', 'status' => 'failed', 'error_code' => 'route.withdraw_failed', 'node_id' => 0]);
    $flow->finish($operation->refresh(), new EngineRun($operation->id, [$failed->name => $failed], 1_800_000_000_000, 1_800_000_001_000));

    expect($operation->refresh()->status->value)->toBe('failed');
    if ($cut) {
        expect($operation->error_code)->toBe('route.withdraw_failed')
            ->and($replacement->refresh()->status)->toBe(RouteStatus::Active)
            ->and($this->route->refresh()->status)->toBe(RouteStatus::Retiring);
    } else {
        expect($operation->error_code)->toBe('route.probe_failed')
            ->and($replacement->refresh()->status)->toBe(RouteStatus::Failed)
            ->and($this->route->refresh()->status)->toBe(RouteStatus::Active);
    }
})->with(['after the cutover' => true, 'before the cutover' => false]);

it('leaves a public Route, a publication change or an analytics change to Swamp', function (array $intent): void {
    $operation = updatedRoute($this->route);
    $input = $operation->input;
    $input['route_update'] = array_replace_recursive($input['route_update'], $intent);
    app(RouteUpdateEngineFlow::class)->steps($operation->forceFill(['input' => $input]));
})->throws(EngineUnsupported::class)->with([
    'a public Route' => [['selected' => ['publication' => 'public']]],
    'a publication change' => [['mode' => 'publication']],
    'an analytics change' => [['analytics_changes' => [['route_id' => 1]]]],
    'the Ingress edge' => [['plan' => ['edge_nodes' => [6]]]],
]);

it('leaves a whole .env write or another URL write to Swamp', function (): void {
    $operation = updatedRoute($this->route);
    $input = $operation->input;
    $input['route_update']['plan']['app_url']['env_files'] = [['node_id' => $this->node->id, 'path' => '/home/orbit/shop/.env']];
    app(RouteUpdateEngineFlow::class)->steps($operation->forceFill(['input' => $input]));
})->throws(EngineUnsupported::class);

it('names a failed step as the Swamp chart\'s iteration', function (): void {
    expect(RouteUpdateEngineFlow::chartStep('dns-0'))->toBe('Move private DNS-0')
        ->and(RouteUpdateEngineFlow::chartStep('sibling-3'))->toBe('Withdraw the old site-3')
        ->and(RouteUpdateEngineFlow::chartStep('site-3'))->toBe('Install cert, publish site-3');
});

it('settles a failed cutover by whether its authority transaction committed', function (bool $committed): void {
    $operation = updatedRoute($this->route);
    $operation->update(['status' => 'running', 'state' => [...$operation->state, 'swamp_run_id' => EngineRun::runIdOf($operation->id)]]);
    $replacement = Route::query()->findOrFail($operation->input['route_update']['result_route_id']);
    if ($committed) {
        app(RouteMutationState::class)->cutover($this->route->refresh(), $replacement);
    }
    $failed = (new OperationEngineStep)->forceFill(['name' => 'cutover', 'status' => 'failed', 'error_code' => 'engine.local_failed', 'node_id' => 0]);
    app(RouteUpdateEngineFlow::class)->finish($operation->refresh(), new EngineRun($operation->id, ['cutover' => $failed], 1_800_000_000_000, 1_800_000_001_000));

    expect($operation->refresh()->status->value)->toBe('failed')
        ->and($replacement->refresh()->status)->toBe($committed ? RouteStatus::Active : RouteStatus::Failed)
        ->and($this->route->refresh()->status)->toBe($committed ? RouteStatus::Retiring : RouteStatus::Active);
})->with(['after its commit' => true, 'before its commit' => false]);

it('leaves an APP_URL write or a retry after the cutover to Swamp', function (string $case): void {
    $operation = updatedRoute($this->route);
    $input = $operation->input;
    if ($case === 'an APP_URL write') {
        $input['route_update']['plan']['app_url']['url_lines'] = [['node_id' => $this->node->id, 'path' => '/home/orbit/shop/.env', 'line' => 'APP_URL=https://renamed.outside.test', 'owner' => 'orbit']];
    } else {
        $input['route_update']['before']['status'] = 'retiring';
    }
    app(RouteUpdateEngineFlow::class)->steps($operation->forceFill(['input' => $input]));
})->throws(EngineUnsupported::class)->with(['an APP_URL write', 'a retry after the cutover']);

it('holds its serving Nodes from admission, so a second update on the same Node waits', function (): void {
    $instance = Instance::query()->create(['project_id' => $this->route->project_id, 'node_id' => $this->node->id, 'name' => 'blog',
        'checkout_path' => '/apps/blog', 'selected_php_version' => '8.5', 'status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false]);
    $sibling = Route::query()->create(['kind' => 'app', 'node_id' => $this->node->id, 'project_id' => $this->route->project_id,
        'domain' => 'blog.outside.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $sibling->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $sibling->update(['status' => 'active', 'sites_published' => true]);

    $first = updatedRoute($this->route);
    $second = updatedRoute($sibling, 'journal.outside.test');

    expect(RoutePublicationHolds::nodes($first))->toContain($this->node->id)
        ->and(DB::table('route_publication_holds')->where('operation_id', $first->id)->pluck('node_id')->all())->toContain($this->node->id)
        ->and($second->refresh()->status->value)->toBe('waiting');
});
