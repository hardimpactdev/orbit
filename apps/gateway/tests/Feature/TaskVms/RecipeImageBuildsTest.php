<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmImageRead;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
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
