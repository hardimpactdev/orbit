<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\EnvironmentSyncExecution;
use App\Domain\Instances\Environment\EnvironmentSyncSecrets;
use App\Domain\Instances\Environment\InstanceServedWebRootsExecution;
use App\Domain\Instances\PhpRuntime\InstancePhpRuntimeExecution;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;

function routeInstanceChild(string $kind = 'instance.prepare_php_runtime'): array
{
    [, $node, , $instance] = deployment_api_fixture();

    $own = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'domain' => 'own.example.test',
        'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $own->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $own->update(['status' => 'active']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => true, 'selected_php_version' => '8.5',
        ...ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes()]);
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $node->id, 'domain' => 'other.example.test',
        'web_root' => 'other/public', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $parent = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1, 'input' => ['route_id' => $route->id,
            'target_instance_ids' => [$instance->id], 'previous_target_instance_ids' => [], 'removed_instance_ids' => []]]);
    $child = new Operation(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $node->id,
        'input' => ['instance_id' => $instance->id, ...($kind === 'instance.sync_environment' ? ['route_domain' => 'authoritative'] : [])]]);
    $child->setAttribute('parent_operation_id', $parent->id);
    $child->setAttribute('child_key', $kind === 'instance.sync_environment' ? 'synchronize-web-root-urls' : 'prepare-runtime');

    return [$child, $parent, $route, $instance];
}

it('renders the activating web-root pool before the Route becomes active', function (): void {
    [$child, , , $instance] = routeInstanceChild();
    $runtime = app(InstancePhpRuntimeExecution::class)->plan($child)['runtime'];
    expect($runtime['mode'])->toBe('converge')->and($runtime['configuration']['pool'])->toContain($instance->production_home.'/current/other');
});

it('keeps full PHP configuration in Gateway state while returning a bounded plan receipt', function (): void {
    [$child] = routeInstanceChild();
    $child->forceFill(['status' => 'running', 'sequence' => 1])->save();
    $execution = app(InstancePhpRuntimeExecution::class);
    $plan = $execution->plan($child);
    $configuration = str_repeat('synthetic-php-configuration', 80);
    $plan['runtime']['configuration']['pool'] = $configuration;

    $receipt = $execution->received($child, ['key' => 'php.plan'], $plan);
    $public = app(OperationExecutionContract::class)->result($child, $receipt);

    expect($public)->toBe(['prepared' => true, 'has_runtime' => true]);
    expect($child->refresh()->state['php_plan']['runtime']['configuration']['pool'])->toBe($configuration);
    $converge = array_find($execution->context($child)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === 'php.converge');
    expect($converge['input']['configuration']['pool'])->toBe($configuration);
});

it('uses admitted previous targets after association removal and withdraws the failed pool', function (): void {
    [$child, $parent, $route, $instance] = routeInstanceChild();
    $route->targets()->delete();
    $route->update(['status' => 'failed', 'failed_step' => 'projection', 'error_code' => 'route.projection_failed', 'sites_published' => false]);
    $parent->update(['kind' => 'route.target_unset', 'input' => [...$parent->input,
        'target_instance_ids' => [], 'previous_target_instance_ids' => [$instance->id]]]);
    $runtime = app(InstancePhpRuntimeExecution::class)->plan($child)['runtime'];
    expect($runtime['configuration']['pool'])->not->toContain($instance->production_home.'/current/other');
});

it('uses protected URL fields and does not rewrite the primary environment during URL-only synchronization', function (): void {
    [$child, , $route, $instance] = routeInstanceChild('instance.sync_environment');
    $route->update(['status' => 'activating']);
    $descriptors = app(EnvironmentSyncExecution::class)->context($child)['descriptors'];
    expect(array_column($descriptors, 'key'))->not->toContain('environment.write', 'environment.testing');
    $url = array_find($descriptors, fn (array $descriptor): bool => $descriptor['name'] === 'instance.write-directory-url');
    expect($url['input']['url'])->toBe('https://other.example.test')
        ->and($url['input']['app_key'])->toBe(['$secret' => 'instance.environment_sync', 'id' => $instance->id])
        ->and($url['input']['app_name_stored'])->toBe(['$secret' => 'instance.environment_sync', 'id' => $instance->id]);
    $secret = app(EnvironmentSyncSecrets::class)->resolve($child, $instance->id, $url['key'], 'app_key');
    expect($secret->value)->toStartWith('APP_KEY="base64:')->and(json_encode($descriptors))->not->toContain($secret->value);
});

it('refuses a Route child whose proposed and previous sets do not own the Instance', function (): void {
    [$child, $parent, $route] = routeInstanceChild();
    $route->targets()->delete();
    $parent->update(['input' => [...$parent->input, 'target_instance_ids' => [], 'previous_target_instance_ids' => []]]);
    expect(fn () => app(InstancePhpRuntimeExecution::class)->plan($child))
        ->toThrow(fn (ResourceOperationException $failure) => expect($failure->errorCode)->toBe('operation.execution_forbidden'));
});

it('prepares the pending production web root before association publication with no environment content', function (): void {
    [$child, , $route] = routeInstanceChild('instance.prepare_served_web_roots');
    $route->targets()->delete();

    $descriptors = app(InstanceServedWebRootsExecution::class)->context($child)['descriptors'];

    $prepare = array_find($descriptors, static fn (array $descriptor): bool => $descriptor['name'] === 'instance.prepare-served-web-roots');
    expect($prepare['input']['served'])->toContain(['web_root' => 'other/public', 'directory' => 'other']);
    expect(array_keys($prepare['input']))->not->toContain('mode', 'content', 'env', 'node_id');
});

it('includes an active Route pool for an admitted target before association commit', function (): void {
    [$child, $parent, $route, $original] = routeInstanceChild();
    $route->update(['status' => 'active']);
    $node = Node::query()->create(['name' => 'prospective-node', 'public_ssh_host' => '192.0.2.199', 'wireguard_ip' => '10.44.0.199', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $instance = Instance::query()->create([
        'project_id' => $original->project_id, 'node_id' => $node->id, 'name' => 'prospective',
        'environment' => 'production', 'checkout_path' => '/home/prospective/releases/initial',
        'production_user' => 'prospective', 'production_home' => '/home/prospective',
        'selected_php_version' => '8.5', 'status' => 'source_resolved',
    ]);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    $child->fill(['target_id' => $instance->id, 'node_id' => $node->id, 'input' => ['instance_id' => $instance->id]]);
    $parent->update(['kind' => 'route.target_set', 'state' => ['route_plan' => [
        'target_instance_ids' => [$original->id, $instance->id], 'previous_target_instance_ids' => [$original->id], 'removed_instance_ids' => [],
    ]]]);

    $runtime = app(InstancePhpRuntimeExecution::class)->plan($child)['runtime'];
    expect($runtime['configuration']['pool'])->toContain($instance->production_home.'/current/other');
});

it('keeps rollback web-root URL synchronization URL-only after the stored root is restored', function (): void {
    [$child, $parent, $route] = routeInstanceChild('instance.sync_environment');
    $child->setAttribute('child_key', 'synchronize-web-root-urls.rollback.'.$child->target_id);
    $parent->update(['kind' => 'route.update', 'input' => [...$parent->input,
        'previous_web_root' => 'previous/public', 'requested_web_root' => 'other/public']]);
    $route->update(['web_root' => 'previous/public', 'status' => 'active']);

    $descriptors = app(EnvironmentSyncExecution::class)->context($child)['descriptors'];
    expect(array_column($descriptors, 'key'))->not->toContain('environment.write', 'environment.testing');
    $url = array_find($descriptors, fn (array $descriptor): bool => $descriptor['name'] === 'instance.write-directory-url');
    expect($url['input']['directory'])->toBe('previous');
});

it('derives a pending replacement web root from its Route parent for PHP preparation', function (): void {
    [$child, $parent, $route, $instance] = routeInstanceChild();
    $candidate = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'replacement.example.test', 'web_root' => 'replacement/public', 'provenance' => 'explicit',
        'publication' => 'public', 'status' => 'pending', 'replaces_route_id' => $route->id, 'replacement_step' => 'reserved']);
    $parent->update(['kind' => 'route.update', 'input' => [...$parent->input, 'candidate_route_id' => $candidate->id]]);
    $runtime = app(InstancePhpRuntimeExecution::class)->plan($child)['runtime'];
    expect($runtime['configuration']['pool'])->toContain($instance->production_home.'/current/replacement');
});

it('publishes shared PHP limits before the Route pool configuration', function (): void {
    [$child] = routeInstanceChild();
    $child->forceFill(['status' => 'running', 'sequence' => 1])->save();
    $execution = app(InstancePhpRuntimeExecution::class);
    $execution->received($child, ['key' => 'php.plan'], $execution->plan($child));
    $descriptors = $execution->context($child)['descriptors'];
    expect(array_column($descriptors, 'key'))->toBe(['php.plan', 'child.php_packages', 'php.profile', 'php.converge', 'php.receipt']);
    expect($descriptors[2]['name'])->toBe('php.configure-runtime')
        ->and($descriptors[2]['input']['runtime_ini'])->toContain('post_max_size = 64M', 'upload_max_filesize = 64M');
});
