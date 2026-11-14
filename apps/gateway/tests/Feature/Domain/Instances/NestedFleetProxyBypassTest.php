<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceNodeServiceTargets;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

const ProxyBase = 'localhost,127.0.0.1,::1,10.253.0.1';
const ProxyFleet = '10.45.0.1,10.45.0.0/24,10.232.2.0/24';

/** @return array{0: Instance, 1: Instance} A default and its clone; `$nested` puts both in the nested project on a switched-on Node. */
function bypassPair(bool $nested): array
{
    $node = containerDefaultNode();
    $node->update(['settings' => [...($node->settings ?? []), 'nested_fleet' => $nested]]);
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $project->forceFill(['nested_fleet' => $nested])->save();
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node->refresh())->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'copy',
        'checkout_path' => '/workspace/copy', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $node, $default);

    return [$default->refresh(), $clone->refresh()];
}

it('builds one proxy bypass from the base, the Node service hosts, the inner fleet and the private names', function (array $endpoints, bool $fleet, array $names, string $value): void {
    expect(InstanceNodeServiceTargets::proxyEnvironment('10.253.0.1', $endpoints, $fleet, $names))->toBe(['NO_PROXY' => $value, 'no_proxy' => $value]);
})->with([
    'a Node service target and a fleet' => [[['host' => '10.44.0.11', 'docker' => false]], true, [], ProxyBase.',10.44.0.11,'.ProxyFleet],
    'only a fleet' => [[], true, [], ProxyBase.','.ProxyFleet],
    'only a Node service target' => [[['host' => '10.44.0.11', 'docker' => false], ['host' => '10.253.0.1', 'docker' => true]], false, [], ProxyBase.',10.44.0.11'],
    // A hand-made `private_names` with one name, as a private websocket app's intent will carry it.
    'only a private name' => [[], false, [['name' => 'reverb.orbit', 'host' => '10.253.0.1', 'port' => 6001]], ProxyBase.',reverb.orbit'],
    'all three' => [[['host' => '10.44.0.11', 'docker' => false]], true, [['name' => 'reverb.orbit', 'host' => '10.253.0.1', 'port' => 6001]],
        ProxyBase.',10.44.0.11,'.ProxyFleet.',reverb.orbit'],
    'a normal container' => [[], false, [], ProxyBase],
]);

it('gives a nested-fleet default and its clone the bypass as container keys, the run environment and the clone\'s proxy value', function (): void {
    [$default, $clone] = bypassPair(true);
    $containers = app(InstanceContainer::class);
    $value = ProxyBase.','.ProxyFleet;

    foreach ([$default, $clone] as $instance) {
        $intent = $containers->intent($instance, $instance->runtime()->firstOrFail());
        expect($intent['project'])->toBe(InstancePlacement::NestedProject)
            ->and([$intent['config']['environment.NO_PROXY'], $intent['config']['environment.no_proxy']])->toBe([$value, $value])
            ->and($intent['environment'])->toContain('NO_PROXY='.$value, 'no_proxy='.$value);
    }
    // The clone script renders the copy's drop-ins from this value (instance_node_services.py `proxy-files`); no boot file holds them.
    $copy = $containers->intent($clone, $clone->runtime()->firstOrFail())['clone'];
    expect($copy['proxy_environment'])->toBe(['NO_PROXY' => $value, 'no_proxy' => $value])
        ->and(array_filter(array_column($copy['boot_files'], 'path'), static fn (string $path): bool => str_contains($path, '99-orbit-node-services')))->toBe([]);
});

it('keeps a normal default and clone without container keys or proxy drop-ins, as before', function (): void {
    [$default, $clone] = bypassPair(false);
    $containers = app(InstanceContainer::class);

    foreach ([$default, $clone] as $instance) {
        $intent = $containers->intent($instance, $instance->runtime()->firstOrFail());
        expect($intent['config'])->not->toHaveKey('environment.NO_PROXY')
            ->and($intent['environment'])->toContain('NO_PROXY='.ProxyBase);
    }
    expect($containers->intent($clone, $clone->runtime()->firstOrFail())['clone'])->not->toHaveKey('proxy_environment');
    expect(array_filter(array_column($containers->intent($clone, $clone->runtime()->firstOrFail())['clone']['boot_files'], 'path'),
        static fn (string $path): bool => str_contains($path, '99-orbit-node-services')))->toBe([]);
});
