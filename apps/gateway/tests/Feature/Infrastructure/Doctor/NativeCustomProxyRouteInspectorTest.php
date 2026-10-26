<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativeCustomProxyRouteInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use Tests\Support\FakeAgentCommandRunner;

it('supplies the private proxy intent to the root agent command', function (string $upstream): void {
    $proxy = custom_proxy_inspector_fixture();
    $proxy->update(['upstream' => $upstream]);
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-custom-proxy', ['caddy' => true, 'tls' => true, 'dns' => true, 'upstream' => true]);

    $observation = new NativeCustomProxyRouteInspector($agents, new CommandDeadline)->inspect($proxy);

    expect($observation->caddyMatches)->toBeTrue();
    expect($observation->upstreamReachable)->toBeTrue();
    expect($agents->calls)->toBe([[
        'node' => 'custom-proxy-node', 'command' => 'doctor.inspect-custom-proxy',
        'input' => [
            'domain' => 'executor.orbit', 'certificates' => '/etc/caddy/orbit-certificates/route-'.$proxy->route_id.'/current',
            'upstream' => substr($upstream, 7), 'dns_address' => '10.44.0.60',
        ],
    ]]);
})->with(['http://127.0.0.1:4788', 'http://[::1]:4788']);

it('keeps drift separate from unknown DNS intent', function (): void {
    $proxy = custom_proxy_inspector_fixture();
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-custom-proxy', ['caddy' => false, 'tls' => false, 'dns' => null, 'upstream' => false]);
    $observation = new NativeCustomProxyRouteInspector($agents, new CommandDeadline)->inspect($proxy);

    expect($observation->caddyMatches)->toBeFalse();
    expect($observation->certificateMatches)->toBeFalse();
    expect($observation->dnsMatches)->toBeNull();
    expect($observation->upstreamReachable)->toBeFalse();
});

it('reports an agent refusal or incomplete result as an inspection failure', function (bool $refuse): void {
    $proxy = custom_proxy_inspector_fixture();
    $agents = new FakeAgentCommandRunner;
    $refuse ? $agents->refuse('doctor.inspect-custom-proxy', 'doctor.projection_invalid') : $agents->answerMalformed('doctor.inspect-custom-proxy', []);

    expect(fn () => new NativeCustomProxyRouteInspector($agents, new CommandDeadline)->inspect($proxy))->toThrow(DoctorInspectionException::class);
})->with([true, false]);

it('keeps custom proxy inspection behind agent commands', function (): void {
    $source = file_get_contents(app_path('Infrastructure/Doctor/NativeCustomProxyRouteInspector.php'));
    expect($source)->not->toContain('SshExecutor', 'ProcessRunner', 'RemoteCommand', 'bash', '<<<');
});

function custom_proxy_inspector_fixture(): RouteCustomProxy
{
    $node = Node::query()->create([
        'name' => 'custom-proxy-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.60',
        'wireguard_ip' => '10.44.0.60',
        'user' => 'orbit',
    ]);
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => 'executor.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:4788']);

    return $route->customProxy()->sole();
}
