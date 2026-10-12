<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

it('marks checkouts on worker Nodes and opts out app-dev Nodes without task work', function (): void {
    $migration = require base_path('database/migrations/2026_10_29_000000_add_worker_shared_to_instances.php');
    $node = static function (string $name, string $ip, RoleName $role, array $settings = []): Node {
        $node = Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'settings' => $settings === [] ? null : $settings]);
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);

        return $node;
    };
    $beast = $node('beast', '10.44.0.7', RoleName::AppDev);
    $beast->processes()->create([
        'name' => 'pi-server',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Stopped,
        'status' => LifecycleStatus::Failed,
    ]);
    $services = $node('services', '10.44.0.3', RoleName::AppDev, ['apps' => ['path' => '/srv/apps']]);
    $production = $node('app-prod', '10.44.0.4', RoleName::AppProd);
    $project = Project::query()->create(['name' => 'Recall', 'slug' => 'recall', 'repository_url' => 'https://example.test/recall.git']);
    $instance = static fn (Node $node, string $name): Instance => Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/recall/{$name}",
        'status' => InstanceState::Active,
    ]);
    $onBeast = $instance($beast, 'beast');
    $onServices = $instance($services, 'main');

    $migration->down();
    $migration->up();

    $shared = DB::table('instances')->pluck('worker_shared', 'id')->map(static fn (mixed $value): bool => (bool) $value);
    expect($shared[$onBeast->id])->toBeTrue()
        ->and($shared[$onServices->id])->toBeFalse()
        ->and($services->refresh()->settings)->toBe(['apps' => ['path' => '/srv/apps'], 'tasks' => ['worker' => 'none']])
        ->and($beast->refresh()->settings)->toBeNull()
        ->and($production->refresh()->settings)->toBeNull();
});
