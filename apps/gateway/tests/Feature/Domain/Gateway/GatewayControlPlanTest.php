<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Gateway\GatewayControlPlan;
use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TestOrbitHome;

uses(LazilyRefreshDatabase::class);

it('renders the serve and worker units for the checkout of the machine, with the release grants and no secret', function (): void {
    $node = SwampNodeFixture::ensure();
    $checkout = realpath(base_path('../../swamp'));

    $plan = app(GatewayControlPlan::class)->make($checkout);

    expect($plan['node_id'])->toBe($node->id)
        ->and($plan['address'])->toBe($node->wireguard_ip)
        ->and($plan['firewall']['hub_port'])->toBe('51820')
        ->and(array_column($plan['firewall']['rules'], 0))->each->toBe('ufw')
        ->and($plan['dns'])->toHaveKeys(['revision', 'listen', 'tlds', 'records'])
        ->and($plan)->not->toHaveKey('dns_backend')
        ->and($plan['user'])->toBe('orbit')
        ->and($plan['units']['serve'])->toContain("--repo-dir {$checkout} ")->toContain("WorkingDirectory={$checkout}\n")
        ->and($plan['units']['worker'])->toContain("--label node={$node->id}")
        ->and($plan['hub']['config'])->toContain('PostUp = wg set %i private-key /etc/wireguard/orbit.key')->not->toContain('PrivateKey')
        ->and($plan['hub']['peer_directory'])->toBe('/etc/wireguard/orbit-peers.d')
        ->and(array_keys($plan['grants']))->toBe(['grants/orbit-gateway.yaml', 'grants/orbit-serve.yaml'])
        ->and(json_encode($plan))->not->toMatch('/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/');
});

it('carries the scheduler and Horizon units the gateway role renders, so the bootstrap starts the same units', function (): void {
    $node = SwampNodeFixture::ensure();
    config(['orbit.gateway_queue.connection' => 'gateway-queue']);

    $plan = app(GatewayControlPlan::class)->make(realpath(base_path('../../swamp')));

    expect(array_column($plan['recorder'], 'name'))->toBe(['orbit-gateway-schedule.service', 'orbit-gateway-horizon.service'])
        ->and(app(GatewayRuntimeFiles::class)->render($node)['units'])->toContain(...$plan['recorder']);
});

it('keeps the production checkout of the swamp Node when no repository is named', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);

    expect($units['serve'])->toContain('--repo-dir /home/orbit/apps/orbit-monorepo-staging/checkout/swamp ');
});

it('renders the worker unit to read its token files itself, so one unit form runs in a VM and in a container', function (): void {
    $worker = SwampRoleUnits::for('orbit', 209)['worker'];

    expect($worker)->toContain('--token-file=/etc/orbit/swamp-role-worker/%i/enrollment.token ', '--server-token-file=/etc/orbit/swamp-role-worker/%i/server.token ', "User=orbit\n")
        ->and($worker)->not->toContain('LoadCredential', '%d');
});

it('records the public key of the hub the machine made, and refuses anything that is not a WireGuard key', function (): void {
    $node = SwampNodeFixture::ensure();
    $key = base64_encode(str_repeat('k', 32));
    $node->roles()->create(['role' => 'vpn', 'status' => 'provisioning']);
    $home = sys_get_temp_dir().'/'.TestOrbitHome::Prefix.'hub-'.bin2hex(random_bytes(4));
    mkdir($home, 0o700);
    config(['orbit.home' => $home]);

    $this->artisan('orbit:bootstrap-control-plan --hub-public-key=not-a-key')->assertFailed();
    $this->artisan("orbit:bootstrap-control-plan --hub-public-key={$key}")->assertSuccessful();

    expect($node->roles()->where('role', 'vpn')->value('status'))->toBe(LifecycleStatus::Active)
        ->and($node->fresh()->wireguard_public_key)->toBe($key)
        ->and(file_get_contents($home.'/wireguard/public.key'))->toBe($key."\n");
});

it('names the folder of the swamp.orbit Route for its certificate and refuses another site', function (): void {
    $node = SwampNodeFixture::ensure();
    $route = Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'swamp.orbit',
        'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $this->app->instance(GatewayCertificateIssuer::class, new class implements GatewayCertificateIssuer
    {
        public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
        {
            return new GatewayCertificatePaths(privateKeyPath: "/ca/{$hostname}.key", certificatePath: "/ca/{$hostname}.pem");
        }
    });

    $this->artisan('orbit:bootstrap-control-plan --certificate=swamp')
        ->expectsOutputToContain('"directory":"/etc/caddy/orbit-certificates/route-'.$route->id.'/current"')
        ->assertSuccessful();
    $this->artisan('orbit:bootstrap-control-plan --certificate=other')->assertFailed();
});

it('records the verifier of the agent key the machine made, and refuses anything but a SHA-256 digest', function (): void {
    $node = SwampNodeFixture::ensure();
    $verifier = hash('sha256', 'agent-key');

    $this->artisan('orbit:bootstrap-control-plan --agent-secret-hash=not-a-digest')->assertFailed();
    $this->artisan("orbit:bootstrap-control-plan --agent-secret-hash={$verifier}")->assertSuccessful();

    expect($node->fresh()->agent_secret_hash)->toBe($verifier);
});

it('marks the swamp role active once the machine serves Swamp', function (): void {
    $node = SwampNodeFixture::ensure();
    $node->roles()->where('role', 'swamp')->update(['status' => 'provisioning']);

    $this->artisan('orbit:bootstrap-control-plan --serving')->assertSuccessful();

    expect($node->roles()->where('role', 'swamp')->value('status'))->toBe(LifecycleStatus::Active);
});
