<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

it('migrates cached pending environment refreshes into durable Instance rows', function (): void {
    $migration = require base_path('database/migrations/2026_11_12_000000_add_environment_runtime_pending_at_to_instances.php');
    $node = Node::query()->create([
        'name' => 'env-node', 'status' => 'active',
        'public_ssh_host' => 'env-node.test', 'wireguard_ip' => '10.44.0.91',
    ]);
    $project = Project::query()->create([
        'name' => 'Environment', 'slug' => 'environment',
        'repository_url' => 'https://example.test/environment.git',
    ]);
    $create = static fn (string $name): Instance => Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id,
        'name' => $name, 'environment' => 'production',
        'checkout_path' => '/srv/environment/'.$name, 'status' => 'active',
    ]);
    $pending = $create('pending');
    $unchanged = $create('unchanged');
    $migration->down();
    Cache::forever('instance-environment-runtime-pending:'.$pending->id, true);
    $this->travelTo(now()->startOfSecond());

    $migration->up();
    Cache::flush();

    expect($pending->refresh()->environment_runtime_pending_at)->toEqual(now())
        ->and($unchanged->refresh()->environment_runtime_pending_at)->toBeNull();

    $migration->down();
    expect(Cache::has('instance-environment-runtime-pending:'.$pending->id))->toBeTrue()
        ->and(Cache::has('instance-environment-runtime-pending:'.$unchanged->id))->toBeFalse();
    $migration->up();
    Cache::flush();
});
