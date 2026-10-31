<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\NodeRole;
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
    $gateway = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    config(['dependency_detection.services_node_id' => $gateway->id, 'swamp.run_token' => 'fixture.token']);
    test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-image-build', 'runId' => '878a384f-8b8a-4066-bd17-dcad8eac3e86',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
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
    expect($operation->state['task_vm_image_writer'])->toBe('direct');
    expect($operation->lock_key)->toBeNull();
    expect($operation->parent_operation_id)->toBeNull();
    expect($operation->input['generation'])->toBe($first->recipe_generation);
});

function nativeImageOperation(): Operation
{
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    app(RecipeImageBuilds::class)->reconcile();

    return Operation::query()->where('kind', 'task_vm.image_build')->sole();
}

function nativeImageResult(array $result, string $status = 'running'): array
{
    return ['run_id' => '878a384f-8b8a-4066-bd17-dcad8eac3e86', 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => $status === 'running' ? null : '2026-10-31T00:01:00.000Z', 'result' => $result];
}

it('starts one native image run with bound safe input and rejects the removed child executor', function (): void {
    $op = nativeImageOperation();
    $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id=878a384f-8b8a-4066-bd17-dcad8eac3e86')
        ->assertOk()->assertJsonPath('data.input.host_node_id', $op->node_id)->assertJsonMissingPath('data.input.resume_from');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $op->kind, 'worker' => 'obsolete'])->assertStatus(422);
    expect(app(OperationKinds::class)->definition('task_vm.image_finish_seed'))->toBeNull();
    expect(app(OperationKinds::class)->definition('task_vm.guest_image_start_phase'))->toBeNull();
    $op->update(['state' => ['task_vm_image_writer' => 'direct']]);
    $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id=878a384f-8b8a-4066-bd17-dcad8eac3e86')
        ->assertStatus(409)->assertJsonPath('error.code', 'operation.run_unbound');
});

it('projects native publication promotion and completion without children or host effects', function (): void {
    $op = nativeImageOperation();
    $path = '/api/v1/direct-operations/'.$op->id.'/result';
    $hash = str_repeat('c', 64);
    $this->postJson($path, nativeImageResult(['phase' => 'publish', 'fingerprint' => $hash]))->assertOk();
    $this->postJson($path, nativeImageResult(['phase' => 'promote', 'completed' => true]))->assertOk();
    $this->postJson($path, nativeImageResult(['ready' => true, 'fingerprint' => $hash], 'succeeded'))->assertOk();
    $build = TaskVmImageBuild::query()->sole();
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($build->image_fingerprint)->toBe($hash)->and($build->images['base']['alias'])->toBe($op->input['candidate_alias'])
        ->and($build->promoted_at)->not->toBeNull();
    $this->assertDatabaseCount('operations', 1);
    $this->postJson($path, nativeImageResult(['ready' => true, 'fingerprint' => $hash], 'succeeded'))->assertOk();
});

it('refuses foreign runs stale attempts and unbounded native results', function (string $change): void {
    $op = nativeImageOperation();
    $body = nativeImageResult(['phase' => 'launch']);
    if ($change === 'run') {
        $body['run_id'] = '878a384f-8b8a-4066-bd17-dcad8eac3e87';
    } elseif ($change === 'attempt') {
        TaskVmImageBuild::query()->sole()->increment('attempt');
    } else {
        $body['result']['stdout'] = 'raw guest output';
    }
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', $body)->assertStatus($change === 'run' ? 409 : 422);
    expect($op->fresh()->status)->toBe(OperationStatus::Queued);
})->with(['run', 'attempt', 'output']);

it('projects native failure without starting another Operation', function (): void {
    $op = nativeImageOperation();
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', [
        ...nativeImageResult(['error_code' => 'task_vm.image_build_failed'], 'failed'), 'error_code' => 'task_vm.image_build_failed',
    ])->assertOk();
    expect(TaskVmImageBuild::query()->sole()->state)->toBe(TaskVmImageBuildState::Failed);
    $this->assertDatabaseCount('operations', 1);
});
