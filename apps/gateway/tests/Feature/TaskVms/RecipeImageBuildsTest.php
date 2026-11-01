<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

function recipeImageHost(): Node
{
    return Node::query()->create(['name' => 'recipe-beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
}

function recipeImageTransport(Node $host): FakeIncusImageHost
{
    $gateway = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    config(['dependency_detection.services_node_id' => $gateway->id, 'swamp.run_token' => 'fixture.token']);
    test()->withServerVariables(['REMOTE_ADDR' => $host->wireguard_ip]);
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
    expect($operation->input['gateway_public_key'])->toBe(app(SwampSshExecutionInput::class)->identity()['public_key']);
    expect($operation->state['swamp_run_id'])->toBe('878a384f-8b8a-4066-bd17-dcad8eac3e86');
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
        ->assertOk()->assertJsonPath('data.input.host_node_id', $op->node_id)->assertJsonMissingPath('data.input.resume_from')
        ->assertJsonMissingPath('data.input.pi_artifact_file')->assertJsonMissingPath('data.input.pi_artifact.path');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $op->kind, 'worker' => 'obsolete'])->assertNotFound();
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
    expect($op->fresh()->status)->toBe(OperationStatus::Running);
})->with(['run', 'attempt', 'output']);

it('projects native failure without starting another Operation', function (): void {
    $op = nativeImageOperation();
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', [
        ...nativeImageResult(['error_code' => 'task_vm.image_build_failed'], 'failed'), 'error_code' => 'task_vm.image_build_failed',
    ])->assertOk();
    expect(TaskVmImageBuild::query()->sole()->state)->toBe(TaskVmImageBuildState::Failed);
    $this->assertDatabaseCount('operations', 1);
});

it('keeps superseded and cancelled attempt ownership readable for native cleanup while refusing further work', function (): void {
    $op = nativeImageOperation();
    $path = '/api/v1/direct-operations/'.$op->id;
    $query = '?run_id=878a384f-8b8a-4066-bd17-dcad8eac3e86';
    $this->getJson($path.$query)->assertOk()->assertJsonPath('data.input.current', true);
    DB::table('task_vm_image_recipe')->where('id', 1)->increment('generation');
    $op->update(['cancel_requested_at' => now()]);
    $this->getJson($path.$query)->assertOk()->assertJsonPath('data.input.current', false)
        ->assertJsonPath('data.cancel_requested', true)->assertJsonPath('data.input.build_id', $op->target_id);
    $this->postJson($path.'/result', nativeImageResult(['phase' => 'launch']))->assertStatus(409)->assertJsonPath('error.code', 'task_vm.image_recipe_superseded');
    $this->postJson($path.'/result', nativeImageResult([], 'cancelled'))->assertOk();
    $this->getJson($path.$query)->assertOk()->assertJsonPath('data.status', 'cancelled');
    TaskVmImageBuild::query()->whereKey($op->target_id)->increment('attempt');
    $this->getJson($path.$query)->assertStatus(422)->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('fences resume once sweep cleanup owns a failed builder or smoke', function (string $suffix): void {
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    $operation->update(['status' => OperationStatus::Failed]);
    $build->update(['state' => TaskVmImageBuildState::Failed]);
    DB::table('task_vm_image_builds')->where('id', $build->id)->update(['updated_at' => now()->subHours(25)]);
    expect(TaskVmHostAudit::claimBuildCleanup($build->hostNode, $build->builder_vm_name.$suffix))->toBeTrue();
    expect(fn () => app(TaskVmImageBuildChain::class)->resume($build->hostNode, $build->id))
        ->toThrow(TaskVmException::class, 'unclaimed failed build');
    expect($build->fresh()->cleanup_started_at)->not->toBeNull();
    $this->assertDatabaseCount('operations', 1);
})->with(['builder' => '', 'smoke' => '-smoke']);

it('rejects a stale sweep claim when a fresh image attempt wins first', function (): void {
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    $old = $build->builder_vm_name;
    $operation->update(['status' => OperationStatus::Failed]);
    $build->update(['state' => TaskVmImageBuildState::Failed]);
    DB::table('task_vm_image_builds')->where('id', $build->id)->update(['updated_at' => now()->subHours(25)]);
    app(TaskVmImageBuildChain::class)->resume($build->hostNode, $build->id);
    expect($build->fresh()->attempt)->toBe(2);
    foreach ([$old, $old.'-smoke'] as $name) {
        expect(TaskVmHostAudit::claimBuildCleanup($build->hostNode, $name))->toBeFalse();
    }
    expect($build->fresh()->cleanup_started_at)->toBeNull();
});

it('rejects stale resume after sweep claims the captured failed row', function (): void {
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    $operation->update(['status' => OperationStatus::Failed]);
    $build->update(['state' => TaskVmImageBuildState::Failed]);
    DB::table('task_vm_image_builds')->where('id', $build->id)->update(['updated_at' => now()->subHours(25)]);
    $claimed = false;
    DB::listen(function (QueryExecuted $query) use ($build, &$claimed): void {
        if (! $claimed && str_starts_with($query->sql, 'select * from "task_vm_image_builds"') && str_contains($query->sql, '"cleanup_started_at" is null')) {
            $claimed = true;
            expect(TaskVmHostAudit::claimBuildCleanup($build->hostNode, $build->builder_vm_name))->toBeTrue();
        }
    });
    expect(fn () => app(TaskVmImageBuildChain::class)->resume($build->hostNode, $build->id))
        ->toThrow(TaskVmException::class, 'Another command claimed');
    expect($claimed)->toBeTrue()->and($build->fresh()->attempt)->toBe(1);
    $this->assertDatabaseCount('operations', 1);
});
