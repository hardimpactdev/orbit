<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;
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
    $gateway = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-pin']);
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

/** Swamp's terminal run data for the image workflow, as the shared reader hands it to the projector. */
function nativeImageResult(string $status, array $steps, ?array $failure = null, array $captures = []): array
{
    return ['run_id' => '878a384f-8b8a-4066-bd17-dcad8eac3e86', 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:30:00.000Z', 'failure' => $failure,
        'result' => ['steps' => array_map(static fn (string $status): array => ['status' => $status], $steps),
            'summary' => ['status' => $status, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'failed_steps' => []], ...$captures]];
}

/** @return array<string,string> */
function nativeImageSteps(string $last = 'complete', string $status = 'failed'): array
{
    $names = ['plan', 'reset-builder', 'reset-builder-reply', 'launch', 'launch-reply', 'boot-wait-agent', 'boot-reply'];
    foreach (TaskVmImageProjection::RecipePhases as $phase) {
        $names = [...$names, $phase.'-start', $phase.'-start-reply', $phase.'-pid', $phase.'-wait', $phase.'-inspect', $phase.'-inspect-reply'];
    }
    $names = [...$names, 'prepare-owner', 'ssh-enable', 'clean-guest', 'clean-guest-reply', 'publish', 'publish-reply', 'smoke-verify', 'ssh-smoke',
        'seed', 'delete-smoke', 'finish-seed-reply', 'promote', 'promote-reply', 'prune', 'prune-reply', 'complete'];
    $steps = [];
    $reached = true;
    foreach ($names as $name) {
        $steps[$name] = $reached ? 'succeeded' : 'skipped';
        if ($name === $last) {
            $steps[$name] = $status === 'succeeded' ? 'succeeded' : 'failed';
            $reached = false;
        }
    }

    return [...$steps, 'cleanup-builder' => 'succeeded', 'cleanup-smoke-reply' => 'succeeded'];
}

it('starts one native image run with the full intent as inputs and no Gateway reads', function (): void {
    $op = nativeImageOperation();
    $socket = app(WebSocketClient::class);
    $inputs = array_last($socket->sent)['payload']['inputs'];
    expect(array_last($socket->sent)['payload']['workflowIdOrName'])->toBe('operation-task-vm-image-build')
        ->and(array_keys($inputs))->toBe(['intent', 'execution_secrets', 'operation_id', 'kind', 'node_id'])
        ->and($inputs['intent']['build_id'])->toBe($op->target_id)->and($inputs['intent'])->not->toHaveKey('pi_artifact_file')
        ->and($inputs['intent'])->not->toHaveKey('current')->and($inputs['intent']['recipe'])->toBeString()
        ->and($inputs['intent']['pi_artifact']['sha256'])->toBe(hash('sha256', 'pinned pi bytes'))
        ->and($inputs['intent']['pi_source']['target']['host'])->toBe('10.44.0.8')
        ->and($inputs['intent']['ssh_execution']['target']['host'])->toBe('10.44.0.7');
    expect(app(DirectOperation::class)->usesNativeTerminal($op))->toBeTrue();
    $this->getJson('/api/v1/direct-operations/'.$op->id.'/private-material?run_id=878a384f-8b8a-4066-bd17-dcad8eac3e86')->assertStatus(422);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $op->kind, 'worker' => 'obsolete'])->assertNotFound();
    expect(app(OperationKinds::class)->definition('task_vm.image_finish_seed'))->toBeNull();
    expect(app(OperationKinds::class)->definition('task_vm.guest_image_start_phase'))->toBeNull();
});

it('refuses to start an attempt whose recipe was superseded before its run', function (): void {
    $op = nativeImageOperation();
    DB::table('task_vm_image_recipe')->where('id', 1)->increment('generation');

    expect(fn () => app(TaskVmImageProjection::class)->input($op->fresh()))
        ->toThrow(TaskVmException::class, 'desired recipe');
});

it('projects publication, promotion, phase seconds and completion from the native terminal run', function (): void {
    $op = nativeImageOperation();
    $hash = str_repeat('c', 64);
    $captures = ['fingerprint' => $hash, 'rust_start' => ['startedAt' => '2026-10-31T00:05:00.000Z'],
        'rust_inspect' => ['finishedAt' => '2026-10-31T00:07:30.500Z']];

    app(TaskVmImageProjection::class)->apply($op, nativeImageResult('succeeded', nativeImageSteps('complete', 'succeeded'), captures: $captures));

    $build = TaskVmImageBuild::query()->sole();
    expect($op->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($op->fresh()->result)->toBe(['ready' => true, 'fingerprint' => $hash]);
    expect($build->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($build->image_fingerprint)->toBe($hash)->and($build->images['base']['alias'])->toBe($op->input['candidate_alias'])
        ->and($build->promoted_at)->not->toBeNull()
        ->and(array_column($build->steps, 'program'))->toBe(['base:reset', 'base:launch', 'base:boot', 'base:apt-source', 'base:packages',
            'base:javascript', 'base:rust', 'base:agent', 'base:pi', 'base:prepare', 'base:clean', 'base:publish', 'base:smoke',
            'base:topology-seed', null, null, 'base:complete'])
        ->and(array_column($build->steps, 'step', 'program')['base:rust'])->toBe('recipe');
    $rust = array_values(array_filter($build->steps, static fn (array $entry): bool => $entry['program'] === 'base:rust'))[0];
    expect($rust['seconds'])->toBe(150.5)->and($rust['started_at'])->toBe('2026-10-31T00:05:00.000000Z');
    $this->assertDatabaseCount('operations', 1);
});

it('refuses foreign runs stale attempts and a success without its promoted image', function (string $change): void {
    $op = nativeImageOperation();
    $data = nativeImageResult('succeeded', nativeImageSteps('complete', 'succeeded'), captures: ['fingerprint' => str_repeat('c', 64)]);
    if ($change === 'run') {
        $data['run_id'] = '878a384f-8b8a-4066-bd17-dcad8eac3e87';
    } elseif ($change === 'attempt') {
        TaskVmImageBuild::query()->sole()->increment('attempt');
    } elseif ($change === 'fingerprint') {
        unset($data['result']['fingerprint']);
    } else {
        $data['result']['steps']['promote-reply']['status'] = 'skipped';
    }
    expect(fn () => app(TaskVmImageProjection::class)->apply($op, $data))->toThrow(ResourceOperationException::class);
    expect($op->fresh()->status)->toBe(OperationStatus::Running);
    expect(TaskVmImageBuild::query()->sole()->image_fingerprint)->toBeNull();
})->with(['run', 'attempt', 'fingerprint', 'promotion']);

it('projects a native failure with the public code of its stage', function (string $step, string $detail, string $code, string $program): void {
    $op = nativeImageOperation();
    $steps = str_starts_with($step, 'cleanup-') ? [...nativeImageSteps('complete', 'succeeded'), $step => 'failed'] : nativeImageSteps($step);

    app(TaskVmImageProjection::class)->apply($op, nativeImageResult('failed', $steps, ['failed_step' => $step, 'detail' => $detail, 'stderr_recorded' => false]));

    $build = TaskVmImageBuild::query()->sole();
    expect($op->fresh()->error_code)->toBe($code)->and($op->fresh()->result)->toBe(['error_code' => $code])
        ->and($op->fresh()->failed_step)->toBe($step);
    expect($build->state)->toBe(TaskVmImageBuildState::Failed)->and($build->current_program)->toBe($program)
        ->and(array_last($build->steps)['error'])->toBe($code);
    $this->assertDatabaseCount('operations', 1);
})->with([
    'agent refusal' => ['clean-guest-reply', 'agent.version_mismatch', 'agent.version_mismatch', 'base:clean'],
    'rebooted builder' => ['rust-inspect-reply', 'task_vm.image_builder_rebooted', 'task_vm.image_builder_rebooted', 'base:rust'],
    'guest agent never ready' => ['boot-wait-agent', 'boot-wait-agent timed out after 630s', 'task_vm.bootstrap_timeout', 'base:boot'],
    'host command' => ['publish', 'The native step failed without stderr.', 'task_vm.image_build_failed', 'base:publish'],
    'cleanup' => ['cleanup-builder', 'The native step failed without stderr.', 'task_vm.image_cleanup_failed', 'base:complete'],
]);

it('projects a native cancellation without a result', function (): void {
    $op = nativeImageOperation();
    app(TaskVmImageProjection::class)->apply($op, nativeImageResult('cancelled', nativeImageSteps('rust')));
    expect($op->fresh()->status)->toBe(OperationStatus::Cancelled)->and($op->fresh()->result)->toBe([]);
    expect(TaskVmImageBuild::query()->sole()->state)->toBe(TaskVmImageBuildState::Failed);
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

it('records failure on the owning build when its Operation terminates outside the phase callback', function (string $status): void {
    $this->freezeTime();
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    $build->update(['current_step' => 'recipe', 'current_program' => 'base:rust', 'steps' => [
        ['step' => 'recipe', 'program' => 'base:rust', 'started_at' => now()->toISOString(), 'seconds' => null, 'error' => null],
    ]]);

    if ($status === 'timeout') {
        $operation->update(['lock_key' => OperationStore::lockKey('task_vm_image', $build->id)]);
        $this->travel(OperationRunBudgets::seconds($operation) + 1)->seconds();
        app(ExpireOperationAction::class)->execute($operation);
    } else {
        app(OperationStore::class)->change($operation, ['status' => OperationStatus::from($status), 'finished_at' => now(),
            'error_code' => $status === 'failed' ? 'operation.native_failed' : null]);
    }

    expect($build->fresh()->state)->toBe(TaskVmImageBuildState::Failed);
    expect($build->fresh()->steps[0]['error'])->toBe(match ($status) {
        'timeout' => 'operation.timed_out', 'failed' => 'operation.native_failed', default => 'operation.cancelled',
    });
    if ($status === 'timeout') {
        expect($operation->fresh()->lock_key)->not->toBeNull();
        expect(ExpireOperationAction::awaitsSettlement($operation->fresh()))->toBeTrue();
    }
})->with(['failed', 'cancelled', 'timeout']);

it('never fails a newer build attempt from a stale terminal image Operation', function (): void {
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    $build->increment('attempt');

    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'error_code' => 'operation.native_failed']);

    expect($build->fresh()->state)->toBe(TaskVmImageBuildState::Running);
});

it('starts a fresh image attempt after a successful sweep proves claimed cleanup finished', function (string $path): void {
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'error_code' => 'operation.native_failed']);
    TaskVmImageBuild::query()->whereKey($build->id)->update(['updated_at' => now()->subHours(25)]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => $message['payload']['workflowIdOrName'], 'runId' => '878a384f-8b8a-4066-bd17-dcad8eac3e86',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    $sweep = app(StartTaskVmSweepOperationAction::class)->execute($build->host_node_id, 'incus');
    expect($build->fresh()->cleanup_started_at)->not->toBeNull();
    app(TaskVmSweepProjection::class)->apply($sweep, [
        'run_id' => $sweep->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['incus' => ['observed_names' => [], 'deleted_names' => [$build->builder_vm_name]]],
    ]);

    $retried = match ($path) {
        'resume' => app(TaskVmImageBuildChain::class)->resume($build->hostNode, $build->id),
        'resume latest' => app(TaskVmImageBuildChain::class)->resumeLatest($build->hostNode),
        default => app(RecipeImageBuilds::class)->reconcile(retry: true)[0],
    };

    expect($retried->id)->toBe($build->id);
    expect($retried->state)->toBe(TaskVmImageBuildState::Running);
    expect($retried->attempt)->toBe(2);
    expect($retried->builder_vm_name)->toBe('tvm-image-'.$build->id.'-attempt-2');
    expect($retried->cleanup_started_at)->toBeNull();
    $this->assertDatabaseCount('operations', 3);
})->with(['resume', 'resume latest', 'recipe retry']);

it('records an exact successful terminal replacement on the same failed image attempt', function (): void {
    $operation = nativeImageOperation();
    $build = TaskVmImageBuild::query()->sole();
    $fingerprint = str_repeat('a', 64);
    $build->update(['image_fingerprint' => $fingerprint, 'promoted_at' => now(), 'current_step' => 'recipe',
        'current_program' => 'base:rust', 'steps' => [['step' => 'recipe', 'program' => 'base:rust', 'error' => null]]]);
    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out']);
    expect($build->fresh()->state)->toBe(TaskVmImageBuildState::Failed);

    // The shared reader owns validation and replacement of the Operation's terminal result.
    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Succeeded, 'error_code' => null,
        'finished_at' => now(), 'result' => ['ready' => true, 'fingerprint' => $fingerprint]]);

    expect($build->fresh()->state)->toBe(TaskVmImageBuildState::Succeeded);
    expect($build->fresh()->image_fingerprint)->toBe($fingerprint);
    expect($build->fresh()->steps[0]['error'])->toBeNull();
    expect($build->fresh()->attempt)->toBe(1);
    expect(app(RecipeImageBuilds::class)->reconcile(retry: true)[0]->attempt)->toBe(1);
    $this->assertDatabaseCount('operations', 1);
});
