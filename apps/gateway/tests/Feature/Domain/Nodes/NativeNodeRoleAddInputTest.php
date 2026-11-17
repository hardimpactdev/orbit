<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\RoleName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    // A Gateway without the marker of an Instance fleet, whatever machine runs the suite.
    config(['instances.fleet_marker' => sys_get_temp_dir().'/orbit-no-instance-fleet-'.getmypid()]);
});

it('retains dedicated status pools when the same Node also publishes shared PHP configuration', function (): void {
    Http::preventStrayRequests();
    $make = static fn (string $name, string $ip): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $metrics = $make('metrics', '10.44.0.3');
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $node = $make('application', '10.44.0.2');
    $assignment = $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'monitored', 'slug' => 'monitored', 'repository_url' => 'https://example.test/monitored.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$project->id;
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/'.$user.'/releases/initial', 'production_user' => $user, 'production_home' => '/home/'.$user, 'root' => 'public', 'selected_php_version' => '8.5']);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    $snapshot = ['gateway_dns' => null, 'prerequisites' => ['releases' => []], 'certificates' => ['leaves' => [], 'withdrawals' => []],
        'metrics_fleet' => ['metrics_node_id' => $metrics->id, 'runtime_files' => ['/etc/orbit/metrics/prometheus.yml' => 'public config'],
            'fleet' => [['node_id' => $node->id, 'worker' => false, 'selected' => true]]]];

    $input = app(NativeNodeRoleAddInput::class)->capture($node, $assignment, $snapshot, [], new Operation);

    expect($input['intent']['publications'][0]['after']['php'])->not->toBeNull();
    expect($input['intent']['exporters'][0]['service']['pools'])->toHaveCount(1);
    expect($input['intent']['exporters'][0]['service']['pools'][0]['php_runtime']['user'])->toBe($user);
    Http::assertNothingSent();
});

it('uses Node apt settings for role prerequisites and exporters regardless of the nested subnet or fleet marker', function (string $host, bool $instanceFleet = false): void {
    Http::preventStrayRequests();
    $marker = tempnam(sys_get_temp_dir(), 'orbit-instance-fleet-');
    if ($instanceFleet) {
        config(['instances.fleet_marker' => $marker]);
    }
    $make = static fn (string $name, string $ip, string $host): Node => Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $host, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $metrics = $make('metrics', '10.44.0.3', '203.0.113.3');
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $node = $make('application', '10.44.0.2', $host);
    $assignment = $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'monitored', 'slug' => 'monitored', 'repository_url' => 'https://example.test/monitored.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$project->id;
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/'.$user.'/releases/initial', 'production_user' => $user, 'production_home' => '/home/'.$user, 'root' => 'public', 'selected_php_version' => '8.5']);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    $snapshot = ['gateway_dns' => null, 'prerequisites' => ['releases' => []], 'certificates' => ['leaves' => [], 'withdrawals' => []],
        'metrics_fleet' => ['metrics_node_id' => $metrics->id, 'runtime_files' => ['/etc/orbit/metrics/prometheus.yml' => 'public config'],
            'fleet' => [['node_id' => $node->id, 'worker' => false, 'selected' => true]]]];

    $intent = app(NativeNodeRoleAddInput::class)->capture($node, $assignment, $snapshot, [], new Operation)['intent'];
    unlink($marker);

    expect($intent)->toHaveKey('apt_proxy')
        ->and($intent['apt_proxy'])->toBeNull()
        ->and($intent['exporters'][0]['apt_proxy'])->toBeNull();
})->with([
    'a nested address without a fleet marker' => ['10.232.2.12'],
    // The play then removes the cache file; apt reads the egress proxy from the fleet's own file.
    'a nested app-dev Node of an Instance fleet' => ['10.232.2.12', true],
    'a Node on the network of its host' => ['10.251.78.178'],
    'a Node with a public address' => ['85.9.211.193'],
]);
