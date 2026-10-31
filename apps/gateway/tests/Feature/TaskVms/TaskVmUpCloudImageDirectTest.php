<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmImageArtifactSource;
use App\Infrastructure\TaskVms\TaskVmImageBootstrapStore;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->node = Node::query()->create(['name' => 'image-gateway', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'user' => 'orbit']);
    $this->manifest = tempnam(sys_get_temp_dir(), 'upcloud-image-delivery-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $identity = 'sha256:'.str_repeat('b', 64);
    DB::table('task_vm_image_recipe')->updateOrInsert(['id' => 1], ['identity' => $identity, 'generation' => 1, 'recipe_json' => '{}']);
    $this->build = UpCloudTaskVmImageBuild::query()->create(['recipe_identity' => $identity, 'state' => 'running', 'started_at' => now()]);
    $input = ['build_id' => $this->build->id, 'node_id' => $this->node->id, 'identity' => $identity, 'generation' => 1,
        'stamp' => '20261031050000', 'name' => 'tvm-image-20261031050000', 'source' => '00000000-0000-4000-8000-000000000001',
        'agent_sha256' => str_repeat('a', 64), 'gateway_public_key' => 'ssh-ed25519 AAAA image', 'ssh_identity' => '/keys/id_ed25519',
        'recipe' => '{}', 'pi_artifact' => ['path' => '/private/pi', 'sha256' => str_repeat('c', 64)], 'upcloud' => ['zone' => 'fi-hel1', 'plan' => 'test-plan', 'disk_gb' => 30],
        'pinned_templates' => [], 'upcloud_token' => ['$secret' => 'upcloud.api', 'id' => $this->build->id],
        'bootstrap' => ['$secret' => 'task_vm.image_bootstrap', 'id' => $this->build->id],
        'pi_artifact_file' => ['$secret' => 'task_vm.image_artifact', 'id' => $this->build->id]];
    $this->build->forceFill(['intent' => $input, 'resources' => []])->save();
    $this->operation = Operation::query()->create(['kind' => 'task_vm.upcloud_image_build', 'target_type' => 'task_vm_upcloud_image',
        'target_id' => $this->build->id, 'node_id' => $this->node->id, 'input' => $input, 'status' => 'queued',
        'state' => ['task_vm_upcloud_image_writer' => 'direct', 'swamp_run_id' => '00000000-0000-4000-8000-000000000007']]);
    $this->projection = app(TaskVmUpCloudImageProjection::class);
});

afterEach(function (): void {
    @unlink($this->manifest);
});

function upcloudImageProjectionBody(string $status, array $result, ?string $code = null): array
{
    return ['run_id' => '00000000-0000-4000-8000-000000000007', 'status' => $status,
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => $result, 'error_code' => $code];
}

it('exposes only safe intent and state with one direct dispatch path', function (): void {
    $input = $this->projection->input($this->operation);
    expect($input)->not->toHaveKeys(['upcloud_token', 'bootstrap', 'pi_artifact_file'])
        ->and($input['pi_artifact'])->toBe(['sha256' => str_repeat('c', 64)])
        ->and(json_decode(json_encode($input, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR)->progress->resources)->toBeInstanceOf(stdClass::class)
        ->and($input['current'])->toBeTrue()
        ->and(app(DirectOperation::class)->writer('task_vm.upcloud_image_build'))->toBe('direct');
    $kind = app(OperationKinds::class)->definition('task_vm.upcloud_image_build');
    expect($kind)->not->toHaveKeys(['runs', 'children', 'claim', 'run_constraints'])
        ->and(array_filter(array_keys(OperationKinds::definitions()), fn (string $name): bool => str_starts_with($name, 'task_vm.upcloud_guest_') || (str_starts_with($name, 'task_vm.upcloud_image_') && $name !== 'task_vm.upcloud_image_build')))->toBe([]);
    Http::assertNothingSent();
});

it('projects one server UUID and refuses a replacement without creating children', function (): void {
    $uuid = '00000000-0000-4000-8000-000000000011';
    $this->projection->apply($this->operation, upcloudImageProjectionBody('running', ['phase' => 'launch-builder', 'resources' => ['builder' => ['uuid' => $uuid]]]));
    expect($this->build->fresh()->resources)->toBe(['builder' => ['uuid' => $uuid]])
        ->and(Operation::query()->count())->toBe(1);
    expect(fn () => $this->projection->apply($this->operation, upcloudImageProjectionBody('running', ['phase' => 'launch-builder', 'resources' => ['builder' => ['uuid' => '00000000-0000-4000-8000-000000000012']]])))
        ->toThrow(ResourceOperationException::class);
});

it('requires native template promotion before accepting success', function (): void {
    $uuid = '00000000-0000-4000-8000-000000000020';
    expect(fn () => $this->projection->apply($this->operation, upcloudImageProjectionBody('succeeded', ['ready' => true, 'template_uuid' => $uuid])))
        ->toThrow(ResourceOperationException::class);
    $this->projection->apply($this->operation, upcloudImageProjectionBody('running', ['phase' => 'templatize', 'template_uuid' => $uuid]));
    $this->projection->apply($this->operation, upcloudImageProjectionBody('running', ['phase' => 'promote', 'template_uuid' => $uuid, 'promoted' => true]));
    $this->projection->apply($this->operation, upcloudImageProjectionBody('succeeded', ['ready' => true, 'template_uuid' => $uuid]));
    expect($this->build->fresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    Http::assertNothingSent();
});

it('keeps cleanup and failure projection available after recipe supersession', function (): void {
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => 2]);
    expect($this->projection->input($this->operation)['current'])->toBeFalse();
    expect(fn () => $this->projection->apply($this->operation, upcloudImageProjectionBody('running', ['phase' => 'smoke'])))
        ->toThrow(ResourceOperationException::class);
    $this->projection->apply($this->operation, upcloudImageProjectionBody('running', ['phase' => 'cleanup']));
    $this->projection->apply($this->operation, upcloudImageProjectionBody('failed', ['error_code' => 'task_vm.image_build_superseded'], 'task_vm.image_build_superseded'));
    expect($this->build->fresh()->state)->toBe(TaskVmImageBuildState::Failed);
});

it('stores worker-generated host keys encrypted and refuses replacement', function (): void {
    $pair = ['private' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----\n", 'public' => 'ssh-ed25519 AAAA fixture'];
    $keys = ['builder' => $pair, 'smoke' => $pair];
    $store = app(TaskVmImageBootstrapStore::class);
    $store->store($this->operation, $this->build->id, $keys);
    expect($this->build->fresh()->bootstrap)->toBe($keys)
        ->and(DB::table('upcloud_task_vm_image_builds')->where('id', $this->build->id)->value('bootstrap'))->not->toContain($pair['private']);
    $keys['smoke']['public'] = 'ssh-ed25519 BBBB foreign';
    expect(fn () => $store->store($this->operation, $this->build->id, $keys))->toThrow(ResourceOperationException::class);
});

it('refuses a private artifact read for a foreign admitted Node', function (): void {
    $source = app(TaskVmImageArtifactSource::class);
    expect($source->ownerId($this->operation))->toBe($this->build->id);
    $this->operation->node_id++;
    expect($source->ownerId($this->operation))->toBeNull();
});

it('records an intent and directly starts one Swamp root without reading the provider token or using SSH', function (): void {
    $this->build->update(['state' => 'failed']);
    $recipe = app(RecipeLoader::class)->load();
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => $recipe->agentDigest('amd64')]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => $recipe->identity]);
    NodeRole::query()->create(['node_id' => $this->node->id, 'role' => 'gateway', 'status' => 'active']);
    TaskVmSettingsBuilder::bind(['upcloud' => new TaskVmUpCloud(false, [], '/token-must-not-be-read', 1, 'fi-hel1', 'test-plan', 30, '00000000-0000-4000-8000-000000000001')]);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/must-not-be-opened';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA fixture';
        }
    });
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-upcloud-image-build', 'runId' => 'ede34ced-7d0e-4764-84c1-cfc533d9e980',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture.token']);
    $operation = app(UpCloudTaskVmImageBuilder::class)->build($recipe);
    expect($operation->kind)->toBe('task_vm.upcloud_image_build')
        ->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->state['task_vm_upcloud_image_writer'])->toBe('direct')
        ->and($operation->state['swamp_run_id'])->toBe('ede34ced-7d0e-4764-84c1-cfc533d9e980')
        ->and($operation->lock_key)->toBeNull()
        ->and(Operation::query()->where('parent_operation_id', $operation->id)->count())->toBe(0);
    Http::assertNothingSent();
});

it('allows an absent optional Pi artifact without opening an artifact path', function (): void {
    $input = $this->operation->input;
    $input['pi_artifact'] = null;
    $this->operation->input = $input;
    expect(app(TaskVmImageArtifactSource::class)->resolve($this->operation, $this->build->id, '', 'pi_artifact_file')->value)->toBeNull();
});
