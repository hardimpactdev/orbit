<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRemovalEnvironmentSource;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Routes\NativeRouteRemovalPublicInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\Fleet\FleetFixtures;

function removalEnvironmentFixture(): array
{
    FleetFixtures::bind();
    $root = FleetFixtures::node('removal-environment-root');
    $retiring = FleetFixtures::node('removal-environment-retiring');
    $worker = FleetFixtures::node('removal-environment-worker');
    $project = Project::query()->create(['name' => 'Existing application', 'slug' => 'removal-environment', 'root' => 'public', 'repository_url' => 'https://github.com/example/app.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $worker->id, 'name' => 'serving',
        'checkout_path' => '/srv/serving', 'selected_php_version' => '8.5', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $worker->id, 'domain' => 'retained.orbit',
        'web_root' => 'public', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $snapshot = app(NodeRemovalPlan::class)->build($retiring, true);
    $routes = ['routes' => [], 'nodes' => [['node_id' => $worker->id,
        'production_instance_ids' => [], 'url_instance_ids' => [$instance->id], 'ingress_firewall' => false]], 'dns_node_ids' => []];
    $input = NodeRemovalEnvironmentSource::bind(['_node_removal' => $snapshot, '_removal' => ['snapshot' => $snapshot, 'routes' => $routes],
        'route_removal' => $routes, 'participant_node_ids' => [$root->id, $worker->id, $retiring->id]], $root->id);
    $operation = Operation::query()->create(['kind' => 'node.remove', 'target_type' => 'node', 'target_id' => $retiring->id,
        'node_id' => $root->id, 'status' => 'running', 'input' => $input]);

    return compact('operation', 'root', 'retiring', 'worker', 'instance');
}

it('hydrates existing URL material only inside its exact workload worker private callback', function (): void {
    $f = removalEnvironmentFixture();
    $operation = $f['operation'];
    $field = $operation->input['environment_fields'][(string) $f['worker']->id];
    $public = app(NativeRouteRemovalPublicInput::class)->forNode($operation, $f['worker']->id);
    expect($public['workloads'])->toHaveCount(1);
    expect($public['workloads'][0])->toMatchArray(['instance_id' => $f['instance']->id, 'node_id' => $f['worker']->id, 'production' => false, 'runtime' => null]);
    $material = app(DirectOperationPrivateMaterial::class)->read($operation, $f['worker']->id);
    expect(array_keys($material))->toBe([$field]);
    expect($material[$field])->toHaveCount(1);
    expect($material[$field][0])->toMatchArray(['instance_id' => $f['instance']->id, 'name' => 'instance.write-development-url']);
    expect($material[$field][0]['input']['url'])->toBe('https://retained.orbit');
    expect($material[$field][0]['input']['app_key'])->toStartWith('APP_KEY=');
    expect(json_encode($operation->fresh()->input))->not->toContain($material[$field][0]['input']['app_key']);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($operation, $f['root']->id))->toThrow(ResourceOperationException::class);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->write($operation, [$field => []], $f['worker']->id))->toThrow(ResourceOperationException::class);
});

it('refuses foreign Node custody, undeclared fields, terminal roots and moved workloads', function (string $drift): void {
    $f = removalEnvironmentFixture();
    $operation = $f['operation'];
    $field = $operation->input['environment_fields'][(string) $f['worker']->id];
    $input = $operation->input;
    match ($drift) {
        'reader' => $input['private_bindings'][$field]['read_node_ids'] = [$f['root']->id],
        'reference' => $input[$field]['id'] = $f['worker']->id,
        'field' => $field = 'removal_environment_61',
        'target' => $operation->target_type = 'task_vm',
        'terminal' => $operation->status = 'succeeded',
        'moved' => $f['instance']->update(['node_id' => $f['root']->id]),
    };
    $operation->input = $input;
    expect(fn () => app(NodeRemovalEnvironmentSource::class)->resolve($operation, $f['retiring']->id, '', $field))->toThrow(ResourceOperationException::class);
})->with(['reader', 'reference', 'field', 'target', 'terminal', 'moved']);
