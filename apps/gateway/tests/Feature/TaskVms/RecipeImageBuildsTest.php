<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmImageRead;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

function recipeImageHost(): Node
{
    return Node::query()->create(['name' => 'recipe-beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
}

function recipeImageTransport(Node $host): FakeIncusImageHost
{
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $incus = new FakeIncusImageHost;
    $incus->bindAgentInventory();
    $incus->recipeImages = true;
    $incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage], IMAGE_OLD => [TaskVmHost::BaseImage]];
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY;
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $directory = sys_get_temp_dir().'/recipe-image-artifacts';
    @mkdir($directory.'/sandbox-artifacts', 0700, true);
    file_put_contents($directory.'/sandbox-artifacts/pi-test', 'pinned pi bytes');
    config(['orbit.home' => $directory]);
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn('public Orbit root certificate');
    app()->instance(LeafCertificateSigner::class, $signer);

    return $incus;
}

function recipeImageDocument(string $snapshot = '20261014T000000Z'): string
{
    $data = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    $data['ubuntu']['snapshot'] = $snapshot;
    $data['tools']['pi']['artifacts']['amd64']['filename'] = 'pi-test';
    $data['tools']['pi']['artifacts']['amd64']['sha256'] = hash('sha256', 'pinned pi bytes');

    return json_encode($data, JSON_THROW_ON_ERROR);
}

function recipeImageCurrent(string $document): void
{
    $directory = sys_get_temp_dir().'/recipe-image-release';
    @mkdir($directory.'/releases/current/apps/gateway/resources/machine-software', 0700, true);
    if (! is_link($directory.'/orbit')) {
        symlink('releases/current', $directory.'/orbit');
    }
    file_put_contents($directory.'/orbit/apps/gateway/resources/machine-software/recipe.json', $document);
    config(['orbit.gateway_checkout' => $directory.'/orbit/apps/gateway']);
}

/** @return array{TaskVmImageBuild, Operation, array<string,mixed>} */
function recipeImageDirectIntent(string $kind = 'task_vm.image_finish_seed'): array
{
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $build = app(RecipeImageBuilds::class)->reconcile()[0];
    $build->update(['images' => ['base' => ['project' => 'orbit-tasks', 'alias' => 'candidate', 'fingerprint' => IMAGE_NEW, 'type' => 'virtual-machine']], 'image_fingerprint' => IMAGE_NEW]);
    $parent = Operation::query()->where('kind', 'task_vm.image_build')->sole();
    $input = ['build_id' => $build->id, 'attempt' => $build->attempt, 'phase' => 'topology-seed', 'effect_key' => 'finish-seed',
        'location' => ['project' => 'orbit-tasks', 'pool' => 'orbit-tasks'], 'operation' => ['kind' => 'finish_seed', 'fingerprint' => IMAGE_NEW]];

    if ($kind === 'task_vm.image_seed') {
        $input = ['build_id' => $build->id, 'attempt' => $build->attempt, 'phase' => 'topology-seed', 'effect_key' => 'topology-seed',
            'project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'vm' => $build->builder_vm_name.'-smoke', 'size' => '45GiB', 'fingerprint' => IMAGE_NEW];
    }

    if ($kind === 'task_vm.image_metadata') {
        $build->update(['images' => [], 'image_fingerprint' => null]);
        $input = ['build_id' => $build->id, 'attempt' => $build->attempt, 'phase' => 'prepare', 'effect_key' => 'metadata',
            'project' => 'orbit-tasks', 'operation' => ['kind' => 'prepare_metadata', 'name' => $build->builder_vm_name]];
    }

    if ($kind === 'task_vm.image_remove') {
        $build->update(['images' => [], 'image_fingerprint' => null]);
        $input = ['build_id' => $build->id, 'attempt' => $build->attempt, 'phase' => 'reset', 'effect_key' => 'reset-builder',
            'vm' => ['project' => 'orbit-tasks', 'name' => $build->builder_vm_name, 'cidr' => '10.251.77.0/24'], 'operation' => ['kind' => 'remove_instance']];
    }

    return [$build, $parent, $input];
}

it('admits the image effect directly and projects its phase once without Gateway execution', function (string $kind): void {
    [$build, $parent, $input] = recipeImageDirectIntent($kind);
    $key = $input['effect_key'];
    $socket = new FakeRoutePublicationSocket;
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-vm-incus', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    $child = app(OperationChildren::class)->admit($parent, ['key' => $key])['operation'];
    expect(DirectOperation::owns($child))->toBeTrue()->and($child->node_id)->toBe($build->host_node_id)
        ->and($child->state['swamp_run_id'])->toBe($run)->and($child->worker)->toBeNull()->and($child->attempts)->toBe(0);
    $projection = app(TaskVmIncusProjection::class);
    expect($projection->input($child)['intent'])->toEqual(array_diff_key($input, array_flip(['phase', 'effect_key'])));
    $outcome = ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => ['ready' => true]];
    $projection->apply($child, $outcome);
    $steps = $build->fresh()->steps;
    $this->travel(30)->seconds();
    $projection->apply($child->fresh(), $outcome);
    expect(app(OperationChildren::class)->requireSucceeded($parent, $key)->id)->toBe($child->id)
        ->and($build->fresh()->steps)->toBe($steps)->and($child->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($child->fresh()->state)->not->toHaveKey('executor_runs');
    expect(array_find($steps, static fn (array $step): bool => $step['program'] === 'base:'.$input['phase'])['operations'][$key])->toBe($child->id);
})->with(['task_vm.image_finish_seed', 'task_vm.image_seed', 'task_vm.image_metadata', 'task_vm.image_remove']);

it('refuses a seed for another host candidate attempt or lifecycle before starting Swamp', function (string $change, string $kind): void {
    [$build, , $input] = recipeImageDirectIntent($kind);
    $nodeId = $build->host_node_id;
    match ($change) {
        'host' => $nodeId++,
        'candidate' => $kind === 'task_vm.image_seed' ? $input['fingerprint'] = str_repeat('d', 64) : $input['operation']['fingerprint'] = str_repeat('d', 64),
        'project' => $kind === 'task_vm.image_seed' ? $input['project'] = 'operator-owned' : $input['location']['project'] = 'operator-owned',
        'pool' => $kind === 'task_vm.image_seed' ? $input['pool'] = 'operator-owned' : $input['location']['pool'] = 'operator-owned',
        'variant' => $kind === 'task_vm.image_seed' ? $input['vm'] = 'operator-owned' : $input['operation'] = ['kind' => 'remove', 'name' => 'operator-owned'],
        'attempt' => $input['attempt']++,
        'phase' => $input['phase'] = 'publish',
        'key' => $input['effect_key'] = 'delete-seed',
        'cleanup' => $build->forceFill(['cleanup_started_at' => now()])->save(),
        'finished' => $build->update(['state' => 'succeeded']),
    };
    expect(fn () => app(TaskVmIncusProjection::class)->admitImage($kind, $build->id, $nodeId, $input))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', $kind)->count())->toBe(0);
})->with(['host', 'candidate', 'project', 'pool', 'variant', 'attempt', 'phase', 'key', 'cleanup', 'finished'])->with(['task_vm.image_finish_seed', 'task_vm.image_seed']);

it('refuses metadata intent for another builder host or lifecycle before starting Swamp', function (string $change): void {
    [$build, , $input] = recipeImageDirectIntent('task_vm.image_metadata');
    $nodeId = $build->host_node_id;
    match ($change) {
        'host' => $nodeId++,
        'attempt' => $input['attempt']++,
        'project' => $input['project'] = 'operator-owned',
        'builder' => $input['operation']['name'] .= '-smoke',
        'variant' => $input['operation'] = ['kind' => 'prune', 'pool' => 'orbit-tasks'],
        'phase' => $input['phase'] = 'publish',
        'cleanup' => $build->forceFill(['cleanup_started_at' => now()])->save(),
        'finished' => $build->update(['state' => 'succeeded']),
    };
    expect(fn () => app(TaskVmIncusProjection::class)->admitImage('task_vm.image_metadata', $build->id, $nodeId, $input))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'task_vm.image_metadata')->count())->toBe(0);
})->with(['host', 'attempt', 'project', 'builder', 'variant', 'phase', 'cleanup', 'finished']);

it('refuses image removal of another Node VM phase or lifecycle before starting Swamp', function (string $change): void {
    [$build, , $input] = recipeImageDirectIntent('task_vm.image_remove');
    $nodeId = $build->host_node_id;
    match ($change) {
        'host' => $nodeId++, 'attempt' => $input['attempt']++,
        'project' => $input['vm']['project'] = 'operator-owned',
        'cidr' => $input['vm']['cidr'] = '10.99.0.0/24',
        'operator-vm' => $input['vm']['name'] = 'operator-ci',
        'live-task-vm' => $input['vm']['name'] = 'tvm-175',
        'other-build' => $input['vm']['name'] = 'tvm-image-'.($build->id + 1),
        'wrong-smoke' => $input['vm']['name'] .= '-smoke',
        'variant' => $input['operation'] = ['kind' => 'delete', 'pool' => 'orbit-tasks'],
        'phase' => $input['phase'] = 'publish', 'key' => $input['effect_key'] = 'operator-delete',
        'cleanup' => $build->forceFill(['cleanup_started_at' => now()])->save(),
        'finished' => $build->update(['state' => 'succeeded']),
    };
    expect(fn () => app(TaskVmIncusProjection::class)->admitImage('task_vm.image_remove', $build->id, $nodeId, $input))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'task_vm.image_remove')->count())->toBe(0);
})->with(['host', 'attempt', 'project', 'cidr', 'operator-vm', 'live-task-vm', 'other-build', 'wrong-smoke', 'variant', 'phase', 'key', 'cleanup', 'finished']);

it('admits each exact image removal key and its matching builder or smoke VM', function (string $key, string $phase, string $suffix): void {
    [$build, , $input] = recipeImageDirectIntent('task_vm.image_remove');
    $input['effect_key'] = $key;
    $input['phase'] = $phase;
    $input['vm']['name'] = $build->builder_vm_name.$suffix;
    $admitted = app(TaskVmIncusProjection::class)->admitImage('task_vm.image_remove', $build->id, $build->host_node_id, $input);
    expect($admitted['vm']['name'])->toBe($build->builder_vm_name.$suffix)->and($admitted['operation'])->toBe(['kind' => 'remove_instance']);
})->with([
    ['reset-builder', 'reset', ''], ['reset-smoke', 'reset', '-smoke'],
    ['delete-builder', 'publish', ''], ['delete-smoke', 'topology-seed', '-smoke'],
    ['reset-seed-smoke', 'topology-seed', '-smoke'],
]);

it('refuses a polled image writer after the execution contract is deleted', function (string $kind): void {
    [, $parent, $input] = recipeImageDirectIntent($kind);
    config(['swamp.direct_writers' => [$kind => 'polled']]);
    expect(fn () => app(OperationChildren::class)->admit($parent, ['key' => $input['effect_key']]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.writer_invalid'));
    expect(Operation::query()->where('kind', $kind)->count())->toBe(0);
})->with(['task_vm.image_finish_seed', 'task_vm.image_seed', 'task_vm.image_metadata', 'task_vm.image_remove']);

it('keeps aliases for base and every role within the Incus name limit', function (string $prefix): void {
    $identity = 'sha256:0bd780fc6472'.str_repeat('a', 52);
    $aliases = [];
    foreach (['base', 'gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        $alias = RecipeImageBuilds::alias($prefix, $identity, $profile, 42);
        expect(strlen($alias))->toBeLessThanOrEqual(64);
        expect($alias)->toEndWith('-recipe-0bd780fc6472-42-'.$profile);
        $aliases[] = $alias;
    }
    expect(array_unique($aliases))->toHaveCount(5);
})->with([TaskVmHost::BaseImage, 'shark-task-base', str_repeat('a', 254)]);

it('records one immutable Swamp image Operation and reuses unchanged intent', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $builds = app(RecipeImageBuilds::class);
    $first = $builds->reconcile()[0];
    $again = $builds->reconcile()[0];
    expect($again->id)->toBe($first->id);
    $operation = Operation::query()->where('kind', 'task_vm.image_build')->sole();
    expect($operation->target_id)->toBe($first->id)->and($operation->node_id)->toBe($host->id);
    expect($operation->input['recipe'])->toBeString();
    expect($operation->input['generation'])->toBe($first->recipe_generation);
});

it('keeps image boot observations pending until the matching guest agent installation is proven', function (bool $smoke, bool $seed): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    app(RecipeImageBuilds::class)->reconcile();
    $parent = Operation::query()->where('kind', 'task_vm.image_build')->sole();

    expect(app(TaskVmImageRead::class)->boot($parent, $smoke, $seed))->toBe(['ready' => false, 'expired' => false]);
    expect($incus->guestCalls)->toBe([]);
})->with(['builder' => [false, false], 'smoke' => [true, false], 'seed' => [true, true]]);

it('observes cloud init after a proven agent installation and preserves real bootstrap failures', function (): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    app(RecipeImageBuilds::class)->reconcile();
    $parent = Operation::query()->where('kind', 'task_vm.image_build')->sole();
    $child = app(OperationChildren::class)->admit($parent, ['key' => 'builder-agent'])['operation'];
    $executor = app(SwampOperationExecutor::class);
    $child = $executor->claim($child->kind, 'builder-install', $host->id, $child->id);
    $result = $executor->run($child, ['worker' => 'builder-install', 'key' => 'guest-install', 'transport' => 'task_vm_guest', 'name' => 'task_vm.guest',
        'input' => ['command' => 'agent.install-guest', 'guest' => $child->input['guest'], 'parameters_json' => '{}']], $host->id);
    $executor->step($child->fresh(), ['worker' => 'builder-install', 'key' => 'finish', 'name' => 'guest-install', 'status' => 'succeeded', 'result' => $result], $host->id);
    $executor->complete($child->fresh(), ['worker' => 'builder-install', 'status' => 'succeeded', 'result' => $result], $host->id);

    expect(app(TaskVmImageRead::class)->boot($parent->fresh(), false))->toBe(['ready' => true, 'expired' => false]);
    expect(array_column($incus->guestCalls, 'command'))->toBe(['guest.cloud-init']);
    expect(app(TaskVmImageRead::class)->boot($parent->fresh(), true))->toBe(['ready' => false, 'expired' => false]);
    expect(app(TaskVmImageRead::class)->boot($parent->fresh(), true, true))->toBe(['ready' => false, 'expired' => false]);
    expect(array_column($incus->guestCalls, 'command'))->toBe(['guest.cloud-init']);
    $incus->fail = 'cloud-init';
    expect(fn () => app(TaskVmImageRead::class)->boot($parent->fresh(), false))->toThrow(TaskVmException::class, 'Cloud-init failed');
});

it('fences an unowned running PHP image attempt and starts Swamp from stock', function (TaskVmImageBuildStep $step, ?string $program): void {
    $host = recipeImageHost();
    $incus = recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $builds = app(RecipeImageBuilds::class);
    $recipe = $builds->currentRecipe();
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => $recipe->identity,
        'recipe_json' => $recipe->json, 'recipe_generation' => 1, 'attempt' => 3,
        'current_step' => $step, 'current_program' => $program, 'source_alias' => 'old-base', 'source_fingerprint' => IMAGE_OLD,
        'image_fingerprint' => IMAGE_NEW, 'images' => ['base' => ['project' => 'orbit-tasks', 'alias' => 'old-candidate', 'fingerprint' => IMAGE_NEW, 'type' => 'virtual-machine']],
        'steps' => [['step' => $step->value, 'program' => $program, 'started_at' => now()->toISOString(), 'seconds' => 12.0, 'error' => null]],
    ]);
    $oldName = $build->builder_vm_name;
    $adopted = $builds->reconcile()[0];
    expect($adopted->id)->toBe($build->id);
    expect($adopted->attempt)->toBe(4);
    expect($adopted->builder_vm_name)->toBe('tvm-image-'.$build->id.'-attempt-4')->not->toBe($oldName);
    expect($adopted->steps)->toBe([]);
    expect($adopted->images)->toBe([]);
    expect($adopted->current_step)->toBeNull();
    expect($adopted->current_program)->toBeNull();
    expect($adopted->source_fingerprint)->toBeNull();
    expect($adopted->image_fingerprint)->toBeNull();
    expect($adopted->from_stock)->toBeTrue();
    $operation = Operation::query()->where('kind', 'task_vm.image_build')->sole();
    expect($operation->input['attempt'])->toBe(4);
    expect($operation->input['name'])->toBe($adopted->builder_vm_name);
    expect($operation->input['source'])->toBe(TaskVmHost::SourceImage);
    expect($operation->input['reuse_published'])->toBeFalse();
    expect($operation->input['resume_from'])->toBeNull();
    expect($builds->reconcile()[0]->attempt)->toBe(4);
    $this->assertDatabaseCount('operations', 1);
    expect($incus->guestCalls)->toBe([]);
})->with([
    'recipe' => [TaskVmImageBuildStep::Recipe, 'base:packages'],
    'promotion' => [TaskVmImageBuildStep::Promote, null],
    'pruning' => [TaskVmImageBuildStep::Prune, null],
]);
