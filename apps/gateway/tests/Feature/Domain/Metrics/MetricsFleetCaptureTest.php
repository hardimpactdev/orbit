<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Nodes\RoleName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

it('captures canonical exporter and service firewall commands without changing current tuples', function (bool $enabled, bool $fpm): void {
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $metrics = $make('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $target = $make('exporter', '10.44.0.2');
    $target->roles()->create(['role' => $fpm ? RoleName::AppProd : RoleName::Database, 'status' => 'active']);
    if ($fpm) {
        $project = Project::query()->create(['name' => 'monitored', 'slug' => 'monitored', 'repository_url' => 'https://example.test/monitored.git', 'default_branch' => 'main', 'root' => 'public']);
        $user = 'orbit-app-'.$project->id;
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $target->id, 'name' => 'default', 'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/'.$user.'/releases/initial', 'production_user' => $user, 'production_home' => '/home/'.$user, 'root' => 'public', 'selected_php_version' => '8.5']);
        $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    }

    $service = app(MetricsFleetCapture::class)->forExporter($metrics, $target, $enabled);

    $prefix = $enabled ? ['ufw'] : ['ufw', '--force', 'delete'];
    expect($service['firewall_commands'])->toBe([
        [...$prefix, 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.3', 'to', '10.44.0.2', 'port', '9100', 'comment', 'orbit:metrics-node-exporter'],
        [...$prefix, 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.3', 'to', '10.44.0.2', 'port', '9102', 'comment', 'orbit:metrics-cadvisor'],
        ['ufw', '--force', 'delete', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.2', 'port', '9103', 'comment', 'orbit:metrics-service-caddy-deny'],
        ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.3', 'to', '10.44.0.2', 'port', '9103', 'comment', 'orbit:metrics-service-caddy-allow'],
        [...($enabled && $fpm ? ['ufw', 'insert', '1'] : ['ufw', '--force', 'delete']), 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.2', 'port', '9114', 'comment', 'orbit:metrics-service-fpm-deny'],
        [...($enabled && $fpm ? ['ufw', 'insert', '1'] : ['ufw', '--force', 'delete']), 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', '10.44.0.3', 'to', '10.44.0.2', 'port', '9114', 'comment', 'orbit:metrics-service-fpm-allow'],
    ]);
    expect(array_column($service['rules'], 'ensure'))->toBe([
        $enabled ? 'present' : 'absent', $enabled ? 'present' : 'absent', $enabled && $fpm ? 'present' : 'absent', 'absent',
    ]);
    Http::assertNothingSent();
})->with([[true, false], [false, false], [true, true], [false, true]]);
