<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'restart-plan', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-host-key', 'wireguard_ip' => '10.44.0.19', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.19']);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Runtime proof', 'slug' => 'runtime-proof', 'repository_url' => 'https://example.test/runtime.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'proof',
        'environment' => 'development', 'checkout_path' => '/srv/proof', 'provisioning_step' => 'active', 'status' => 'active']);
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
    $this->runtime = app(ProcessInstanceOperation::class);
});

it('uses a provided admitted Process target without resolving current owner placement', function (): void {
    $process = runtimeRestartProcess($this->instance, 'admitted-target');
    $target = new ProcessTarget($this->node, 'orbit', '/srv/captured', instance: $this->instance);
    $process->owner_type = Node::class;
    $process->owner_id = 999999;
    $input = app(ProcessOperationAdmission::class)->inputFor($process, 'restart', target: $target);
    expect($input['node_id'])->toBe($this->node->id);
});

it('publishes public member definitions with distinct environment copies and no child Operations', function (): void {
    $first = runtimeDefinitionProcess($this->instance, 'first', 'first-private-fixture');
    $second = runtimeDefinitionProcess($this->instance, 'second', 'second-private-fixture');
    $operation = $this->runtime->execute($this->instance, 'process.install_instance_definitions');
    $runs = array_values(array_filter($this->socket->sent, fn (array $request): bool => $request['type'] === 'workflow.run'));
    $puts = array_values(array_filter($this->socket->sent, fn (array $request): bool => $request['type'] === 'vault.put'));
    $input = $runs[0]['payload']['inputs'];
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['payload']['workflowIdOrName'])->toBe('operation-process-install-instance-definitions')
        ->and(array_column($input['intent']['members'], 'id'))->toBe([$first->id, $second->id])
        ->and($input['intent']['members'][0]['intent']['installation']['definition']['image'])->toBe('example:1')
        ->and($input['intent']['members'][0]['intent']['installation']['definition'])->not->toHaveKey('environment')
        ->and($input['intent']['members'][0]['intent']['installation']['desired'])->toBe('stopped')
        ->and(json_encode($input))->not->toContain('first-private-fixture', 'second-private-fixture')
        ->and($puts)->toHaveCount(2)
        ->and(array_column(array_column($puts, 'payload'), 'key'))->toBe(['process-instance-operation-'.$operation->id.'-member-'.$first->id, 'process-instance-operation-'.$operation->id.'-member-'.$second->id])
        ->and($puts[0]['payload']['value'])->toBe("PASSWORD=first-private-fixture\n")
        ->and($puts[1]['payload']['value'])->toBe("PASSWORD=second-private-fixture\n")
        ->and($input['execution_secrets']['member_'.$first->id]['key'])->toBe($puts[0]['payload']['key'])
        ->and($input['execution_secrets']['member_'.$second->id]['key'])->toBe($puts[1]['payload']['key'])
        ->and($operation->input)->not->toHaveKey('installations')
        ->and($operation->input['members'][0]['input'])->not->toHaveKey('agent_sha256')
        ->and(Operation::query()->count())->toBe(1);
    expect(app(DirectOperation::class)->definition($operation->kind))->not->toHaveKey('private_material');
});

it('refuses changed or foreign definitions before publishing their environment', function (string $change): void {
    $process = runtimeDefinitionProcess($this->instance, 'copy', 'instance-private-fixture');
    $operation = runtimeDefinitionParent($this->instance, $this->runtime->capture($this->instance, 'process.install_instance_definitions'));
    $process->update(match ($change) {
        'definition' => ['runtime_config' => [...$process->runtime_config, 'image' => 'example:2']],
        'owner' => ['owner_type' => Node::class, 'owner_id' => $this->node->id],
    });
    expect(fn () => $this->runtime->input($operation))->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'operation.execution_forbidden')
        ->and($this->socket->sent)->toBe([]);
})->with(['definition', 'owner']);

it('projects native root acceptance into the captured set without trusting member output', function (string $status): void {
    $process = runtimeDefinitionProcess($this->instance, 'copy', 'instance-private-fixture');
    $operation = runtimeDefinitionParent($this->instance, $this->runtime->capture($this->instance, 'process.install_instance_definitions'));
    $this->runtime->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'error_code' => in_array($status, ['failed', 'interrupted'], true) ? 'operation.native_failed' : null,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'members' => [['id' => $process->id, 'ready' => true, 'stdout' => 'private-extra']]]]);
    expect($operation->refresh()->status->value)->toBe($status)
        ->and($process->refresh()->status->value)->toBe(match ($status) {
            'succeeded' => 'active', 'cancelled' => 'provisioning', default => 'failed'
        })
        ->and($process->desired_state->value)->toBe('stopped')
        ->and(json_encode($operation->result))->not->toContain('members', 'private-extra')
        ->and(Operation::query()->count())->toBe(1);
})->with(['succeeded', 'failed', 'interrupted', 'cancelled']);

it('installs no members and publishes no environment for an empty definition selection', function (): void {
    $operation = runtimeDefinitionParent($this->instance, $this->runtime->capture($this->instance, 'process.install_instance_definitions'));
    expect($this->runtime->input($operation))->toBe(['intent' => ['instance_id' => $this->instance->id, 'members' => []], 'execution_secrets' => []])
        ->and($this->socket->sent)->toBe([]);
});

it('publishes a public systemd definition and records its admitted activation hash', function (): void {
    $definition = ProcessDefinition::query()->create(['project_id' => $this->instance->project_id, 'name' => 'systemd-copy', 'runtime' => 'systemd', 'environments' => ['production'], 'spec' => []]);
    $process = runtimeRestartProcess($this->instance, 'systemd-copy', ['source_definition_id' => $definition->id, 'status' => 'provisioning', 'working_directory' => '/srv/proof']);
    $operation = runtimeDefinitionParent($this->instance, $this->runtime->capture($this->instance, 'process.install_instance_definitions'));
    $input = $this->runtime->input($operation);
    $installation = $input['intent']['members'][0]['intent']['installation'];
    expect($installation['unit'])->toContain('ExecStart=')
        ->and(hash('sha256', $installation['unit']))->toBe($installation['content_hash'])
        ->and($installation['has_environment'])->toBeFalse()
        ->and($input['execution_secrets'])->toBe([]);
    $this->runtime->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['ready' => true]]);
    expect($process->refresh()->systemd_activation_hash)->toBe($installation['content_hash'])
        ->and($process->systemd_activation_pending)->toBeFalse()
        ->and($process->desired_state->value)->toBe('running');
});

/** @param array<string,mixed> $attributes */
function runtimeRestartProcess(Instance $instance, string $name, array $attributes = []): Process
{
    return Process::query()->create([...['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'name' => $name,
        'runtime' => 'systemd', 'restart_policy' => 'never', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300']],
        'status' => 'active', 'desired_state' => 'running', 'restart_on_deploy' => true], ...$attributes]);
}

function runtimeDefinitionProcess(Instance $instance, string $name, string $secret): Process
{
    $definition = ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => $name, 'runtime' => 'docker', 'environments' => ['production'], 'spec' => []]);

    return runtimeRestartProcess($instance, $name, ['status' => 'provisioning', 'desired_state' => 'stopped', 'runtime' => 'docker', 'source_definition_id' => $definition->id,
        'runtime_config' => ['image' => 'example:1', 'command' => ['/usr/bin/sleep', '300'], 'environment' => ['PASSWORD' => $secret]]]);
}

/** @param array<string,mixed> $input */
function runtimeDefinitionParent(Instance $instance, array $input): Operation
{
    return Operation::query()->create(['kind' => 'process.install_instance_definitions', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'input' => $input, 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}
