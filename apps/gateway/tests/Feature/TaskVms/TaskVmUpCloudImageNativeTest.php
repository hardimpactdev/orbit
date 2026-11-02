<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use App\Models\Node;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

const UpCloudImagePrivate = "-----BEGIN OPENSSH PRIVATE KEY-----\nfixture-private-image-host-key\n-----END OPENSSH PRIVATE KEY-----\n";
const UpCloudImageToken = 'ucat_fixture_private_image_token';

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->node = Node::query()->create(['name' => 'image-gateway', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active',
        'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAgateway']);
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->manifest = tempnam(sys_get_temp_dir(), 'upcloud-image-delivery-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->identity = 'sha256:'.str_repeat('b', 64);
    DB::table('task_vm_image_recipe')->updateOrInsert(['id' => 1], ['identity' => $this->identity, 'generation' => 1, 'recipe_json' => '{}']);
    $settings = TaskVmSettingsBuilder::bind(['upcloud' => new TaskVmUpCloud(false, [], '/fixture/upcloud', 1, 'fi-hel1', 'test-plan', 30, '00000000-0000-4000-8000-000000000001')]);
    app()->instance(UpCloudApi::class, new UpCloudApi($settings, UpCloudImageToken));
    $this->build = UpCloudTaskVmImageBuild::query()->create(['recipe_identity' => $this->identity, 'state' => 'running', 'started_at' => now()]);
    $this->hostKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG tvm-image-20261031050000';
    $input = ['build_id' => $this->build->id, 'node_id' => $this->node->id, 'identity' => $this->identity, 'generation' => 1,
        'stamp' => '20261031050000', 'name' => 'tvm-image-20261031050000', 'source' => '00000000-0000-4000-8000-000000000001',
        'pi_artifact' => ['path' => '/srv/orbit/sandbox-artifacts/pi', 'sha256' => str_repeat('c', 64)],
        'recipe' => json_encode(['identity' => $this->identity, 'agent_sha256' => str_repeat('a', 64), 'pi_sha256' => str_repeat('c', 64)], JSON_THROW_ON_ERROR),
        'agent_sha256' => str_repeat('a', 64), 'host_key' => $this->hostKey,
        'upcloud' => ['zone' => 'fi-hel1', 'plan' => 'test-plan', 'disk_gb' => 30], 'pinned_templates' => []];
    $this->build->forceFill(['intent' => $input, 'resources' => [], 'bootstrap' => ['private' => UpCloudImagePrivate, 'public' => $this->hostKey]])->save();
    $this->run = '00000000-0000-4000-8000-000000000007';
    $this->operation = Operation::query()->create(['kind' => 'task_vm.upcloud_image_build', 'target_type' => 'task_vm_upcloud_image',
        'target_id' => $this->build->id, 'node_id' => $this->node->id, 'input' => $input, 'status' => 'running', 'state' => ['swamp_run_id' => $this->run]]);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $message): array {
        if ($message['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-vm-upcloud-image-build', 'runId' => $this->run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.run', 'swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
});

afterEach(function (): void {
    @unlink($this->manifest);
});

/** One native terminal history read, as the shared reader receives it. */
function upcloudImageTerminal(string $status, array $build, array $check = [], array $settle = []): Operation
{
    $step = static fn (string $name, string $state, array $outputs = []): array => ['name' => $name, 'status' => $state, 'outputs' => $outputs];
    $history = ['id' => test()->run, 'workflowName' => 'operation-task-vm-upcloud-image-build', 'status' => $status,
        'inputs' => ['operation_id' => test()->operation->id, 'node_id' => test()->operation->node_id, 'kind' => 'task_vm.upcloud_image_build'],
        'startedAt' => '2026-11-02T00:00:00Z', 'completedAt' => '2026-11-02T00:30:00Z', 'jobs' => [
            ['name' => 'build', 'steps' => array_map(static fn (array $entry): array => $step(...$entry), $build)],
            ['name' => 'settle', 'steps' => array_map(static fn (array $entry): array => $step(...$entry), $settle)],
            ['name' => 'check', 'steps' => array_map(static fn (array $entry): array => $step(...$entry), $check)],
        ]];
    test()->socket->reply = static fn (array $sent): array => [['type' => 'workflow.history.get', 'id' => $sent['id'], 'payload' => ['data' => $history]]];
    $selector = app(DirectOperation::class)->definition('task_vm.upcloud_image_build')['terminal_result'];

    return app(SwampCommandStep::class)->projectTerminal(test()->operation, ['id' => test()->run, 'status' => $status], $selector);
}

it('passes the full public intent and only vault key names to the run', function (): void {
    $inputs = app(DirectOperation::class)->workflowInputs($this->operation);
    $intent = $inputs['intent'];
    expect($inputs['execution_secrets'])->toBe([
        'upcloud' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-upcloud-token'],
        'bootstrap' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-image-bootstrap-'.$this->build->id]])
        ->and($intent['recipe'])->toBe(['identity' => $this->identity, 'agent_sha256' => str_repeat('a', 64), 'pi_sha256' => str_repeat('c', 64)])
        ->and($intent['host_key'])->toBe($this->hostKey)
        ->and($intent['ssh'])->toBe(['identity_file' => '/etc/orbit/swamp-role-worker/ssh-identity',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND'])
        ->and($intent['pi_source']['target'])->toBe(['name' => 'target', 'host' => '10.44.0.14', 'user' => 'orbit', 'port' => 22, 'host_keys' => ['ssh-ed25519 AAAAgateway']])
        ->and($intent['pi_artifact'])->toBe(['path' => '/srv/orbit/sandbox-artifacts/pi', 'sha256' => str_repeat('c', 64)])
        ->and(json_decode($intent['template_data'], true))->toBe(['storage' => ['title' => 'orbit-task-base-20261031050000-unverified']])
        ->and(json_decode($intent['promote_data'], true)['storage']['labels'])->toBe([
            ['key' => 'orbit.recipe', 'value' => $this->identity], ['key' => 'orbit-task-image', 'value' => '20261031050000']]);
    $key = ['identity' => $this->identity, 'profile' => 'base', 'phase' => 'rust', 'execution' => 'upcloud-'.$this->build->id];
    expect($intent['phase_keys']['rust'])->toBe($key)
        // The agent's own unit rule: the first 32 hex of the SHA-256 of identity:profile:phase:execution.
        ->and($intent['units']['rust'])->toBe('orbit-recipe-image-'.substr(hash('sha256', implode(':', $key)), 0, 32).'.service');
    $install = json_decode($intent['envelopes']['install'], true);
    expect($install)->toBe(['agent_sha256' => str_repeat('a', 64), 'deadline_ms' => 180000,
        'input' => ['candidate' => '/var/tmp/orbit-agent-candidate-'.str_repeat('a', 64), 'sha256' => str_repeat('a', 64)]])
        ->and(json_decode($intent['envelopes']['check'], true)['input'])->toBe(['lean_boot' => false, 'firewall' => true])
        ->and(json_decode($intent['envelopes']['inspect_boot'], true)['input']['key']['phase'])->toBe('apt-source');
    // The vault holds the token and the private key alone; neither reaches the inputs or the Operation.
    $puts = array_column(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'vault.put'), 'payload');
    expect(array_column($puts, 'value'))->toBe([UpCloudImageToken, UpCloudImagePrivate])
        ->and(json_encode($inputs))->not->toContain('fixture-private-image-host-key')->not->toContain(UpCloudImageToken)
        ->and(json_encode($this->operation->fresh()->getAttributes()))->not->toContain('fixture-private-image-host-key');
    expect(app(OperationKinds::class)->definition('task_vm.upcloud_image_build'))->not->toHaveKeys(['private_material', 'secret_outputs', 'writer_config']);
    Http::assertNothingSent();
});

it('refuses a superseded attempt at the run start before any vault copy', function (): void {
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => 2]);
    expect(fn () => app(DirectOperation::class)->workflowInputs($this->operation))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('task_vm.image_build_superseded'));
    expect($this->socket->sent)->toBe([]);
});

it('records the checked, promoted template from the native run', function (): void {
    $uuid = '00000000-0000-4000-8000-000000000070';
    $projected = upcloudImageTerminal('succeeded', [['Launch the builder', 'succeeded'], ['Read the template', 'succeeded', ['template_uuid' => $uuid]],
        ['Promote the template', 'succeeded']], [['Check the template', 'succeeded']], [['Plan the cleanup', 'succeeded']]);
    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['ready' => true, 'template_uuid' => $uuid])
        ->and($this->build->fresh()->state)->toBe(TaskVmImageBuildState::Succeeded)
        ->and($this->build->fresh()->template_uuid)->toBe($uuid)
        ->and($this->build->fresh()->finished_at)->not->toBeNull();
});

it('refuses a succeeded run without its end check or template', function (): void {
    expect(fn () => app(TaskVmUpCloudImageProjection::class)->apply($this->operation, ['run_id' => $this->run, 'status' => 'succeeded',
        'result' => ['template_uuid' => '00000000-0000-4000-8000-000000000070', 'steps' => ['Check the template' => ['status' => 'skipped']]]]))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => app(TaskVmUpCloudImageProjection::class)->apply($this->operation, ['run_id' => $this->run, 'status' => 'succeeded',
        'result' => ['steps' => ['Check the template' => ['status' => 'succeeded']]]]))
        ->toThrow(ResourceOperationException::class);
    expect($this->build->fresh()->state)->toBe(TaskVmImageBuildState::Running);
});

it('keeps a refused agent reply code, maps UpCloud steps, and fails the attempt', function (string $step, string $detail, string $code): void {
    $projected = app(TaskVmUpCloudImageProjection::class)->apply($this->operation, ['run_id' => $this->run, 'status' => 'failed',
        'started_at' => '2026-11-02T00:00:00Z', 'finished_at' => '2026-11-02T00:10:00Z', 'error_code' => 'operation.native_failed',
        'result' => ['steps' => [$step => ['status' => 'failed']]], 'failure' => ['failed_step' => $step, 'detail' => $detail, 'stderr_recorded' => false]]);
    expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe($code)
        ->and($projected->result)->toBe(['error_code' => $code])
        ->and($this->build->fresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($this->build->fresh()->template_uuid)->toBeNull()
        ->and($this->build->fresh()->failure['stderr'])->toBe($code);
})->with([
    'smoke refusal' => ['Smoke the template', 'agent.version_mismatch', 'agent.version_mismatch'],
    'provider request' => ['Make the template', 'curl: (22) The requested URL returned error: 409', 'task_vm.upcloud_failed'],
    'other step' => ['Run the phases', 'The native step failed without stderr.', 'task_vm.image_build_failed'],
]);

it('marks a cancelled attempt failed with no template', function (): void {
    $projected = app(TaskVmUpCloudImageProjection::class)->apply($this->operation, ['run_id' => $this->run, 'status' => 'cancelled',
        'started_at' => '2026-11-02T00:00:00Z', 'finished_at' => '2026-11-02T00:10:00Z', 'result' => []]);
    expect($projected->status)->toBe(OperationStatus::Cancelled)->and($projected->result)->toBe([])
        ->and($this->build->fresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($this->build->fresh()->failure['stderr'])->toBe('operation.cancelled');
});

it('admits one attempt with a fresh host key pair and starts one native run after commit', function (): void {
    $this->build->update(['state' => 'failed']);
    $this->operation->update(['status' => 'failed']);
    $recipe = app(RecipeLoader::class)->load();
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => $recipe->agentDigest('amd64')]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => $recipe->identity]);
    $this->socket->sent = [];

    $operation = app(UpCloudTaskVmImageBuilder::class)->build($recipe);

    $build = UpCloudTaskVmImageBuild::query()->findOrFail($operation->target_id);
    expect($operation->kind)->toBe('task_vm.upcloud_image_build')
        ->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and($operation->input)->not->toHaveKeys(['upcloud_token', 'bootstrap', 'pi_artifact_file', 'ssh_identity', 'gateway_public_key'])
        ->and($operation->input['host_key'])->toBe($build->bootstrap['public'])
        ->and($build->bootstrap['private'])->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
        ->and(DB::table('upcloud_task_vm_image_builds')->where('id', $build->id)->value('bootstrap'))->not->toContain('OPENSSH')
        ->and(json_encode($operation->getAttributes()))->not->toContain($build->bootstrap['private'])
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'vault.put', 'workflow.run'])
        ->and(Operation::query()->where('parent_operation_id', $operation->id)->count())->toBe(0);
    Http::assertNothingSent();
});
