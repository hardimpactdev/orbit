<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\ProcessInstallationBatch;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->source = Node::query()->create(['name' => 'batch-source', 'wireguard_ip' => '10.44.0.11', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'source-public-pin', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.11']);
    $this->destination = Node::query()->create(['name' => 'batch-destination', 'wireguard_ip' => '10.44.0.12', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'destination-public-pin', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.12']);
    $project = Project::query()->create(['name' => 'Batch', 'slug' => 'batch-proof', 'repository_url' => 'https://example.test/batch.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id, 'name' => 'proof', 'checkout_path' => '/srv/source', 'status' => 'active', 'provisioning_step' => 'active']);
    $this->process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id, 'name' => 'batch-proof',
        'runtime' => 'systemd', 'working_directory' => '/srv/source/app', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300'], 'environment_file' => '/srv/source/.env', 'environment' => ['PASSWORD' => 'batch-private-value']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            throw new RuntimeException('Account discovery must not run in batch admission or private hydration.');
        }
    });
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture']);
    $this->batch = app(ProcessInstallationBatch::class);
    $this->target = function (Node $node, string $checkout): ProcessTarget {
        $placed = clone $this->instance;
        $placed->node_id = $node->id;
        $placed->checkout_path = $checkout;
        $placed->setRelation('node', $node);

        return new ProcessTarget($node, 'orbit', $checkout, certificateScope: 'app-instance-'.$placed->id, instance: $placed, environmentFile: $checkout.'/.env');
    };
    $this->captures = fn (): array => ['source_installations' => $this->batch->capture($this->instance, ($this->target)($this->source, '/srv/source')),
        'destination_installations' => $this->batch->capture($this->instance, ($this->target)($this->destination, '/srv/destination'))];
    $this->operation = function (array $captures): Operation {
        $operation = new Operation;
        $operation->forceFill(['id' => 123, 'kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->source->id,
            'input' => ['process_installations' => $captures, 'participant_node_ids' => [$this->source->id, $this->destination->id],
                'private_bindings' => ['source_installations' => ['owner_node_id' => $this->source->id, 'read_node_ids' => [$this->source->id], 'write_node_ids' => []],
                    'destination_installations' => ['owner_node_id' => $this->destination->id, 'read_node_ids' => [$this->destination->id], 'write_node_ids' => []]]]]);

        return $operation;
    };
});

it('publishes both prospective public definitions and distinct environment-only copies before cutover', function (): void {
    $operation = ($this->operation)(($this->captures)());
    $source = $this->batch->nativeInput($operation, 'source_installations');
    $destination = $this->batch->nativeInput($operation, 'destination_installations');
    expect($source['members'][0]['intent']['installation']['unit'])->toContain('/srv/source/app', ProcessOperationAdmission::AccountHomeToken)
        ->and($destination['members'][0]['intent']['installation']['unit'])->toContain('/srv/destination/app', '/srv/destination/.env')
        ->and(json_encode([$source, $destination]))->not->toContain('batch-private-value')
        ->and($source['execution_secrets'])->not->toBe($destination['execution_secrets'])
        ->and($this->socket->sent)->toHaveCount(2)
        ->and($this->socket->sent[0]['payload']['value'])->toContain('batch-private-value')
        ->and($this->socket->sent[0]['payload']['value'])->not->toContain('[Service]')
        ->and($destination['members'][0]['intent']['ssh_execution']['target']['host_keys'])->toBe(['ssh-ed25519 destination-public-pin'])
        ->and($this->instance->fresh()->node_id)->toBe($this->source->id)
        ->and($this->process->fresh()->working_directory)->toBe('/srv/source/app')
        ->and(Operation::query()->count())->toBe(0);
});

it('refuses changed origin, membership, ownership, specification or destination before publishing copies', function (string $change): void {
    $operation = ($this->operation)(($this->captures)());
    match ($change) {
        'origin' => $this->instance->update(['checkout_path' => '/srv/changed']),
        'owner' => $this->process->update(['owner_id' => $this->instance->id + 100]),
        'member' => $this->process->replicate()->fill(['name' => 'new-member'])->save(),
        'spec' => $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'environment' => ['PASSWORD' => 'changed']]]),
        'destination' => $this->destination->update(['status' => 'removing']),
        'descriptor' => $operation->input = array_replace_recursive($operation->input, ['process_installations' => ['destination_installations' => ['members' => [['input' => ['content_hash' => str_repeat('a', 64)]]]]]]),
    };
    expect(fn () => $this->batch->nativeInput($operation, 'destination_installations'))->toThrow(ResourceOperationException::class)
        ->and($this->socket->sent)->toBe([]);
})->with(['origin', 'owner', 'member', 'spec', 'destination', 'descriptor']);

it('publishes Docker intent without its environment and preserves null defaults', function (): void {
    $this->process->update(['runtime' => 'docker', 'working_directory' => null, 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300'], 'environment_file' => null, 'environment' => ['TOKEN' => 'docker-private']]]);
    $native = $this->batch->nativeInput(($this->operation)(($this->captures)()), 'destination_installations');
    expect($native['members'][0]['intent']['installation']['definition']['working_directory'])->toBeNull()
        ->and($native['members'][0]['intent']['installation']['definition'])->not->toHaveKey('environment')
        ->and(json_encode($native))->not->toContain('docker-private')
        ->and(json_decode($this->socket->sent[0]['payload']['value'], true))->toBe(['TOKEN' => 'docker-private']);
});

it('refuses foreign paths and undeclared selectors without effects', function (): void {
    $operation = ($this->operation)(($this->captures)());
    expect(fn () => $this->batch->nativeInput($operation, 'unknown'))->toThrow(ResourceOperationException::class);
    $this->process->update(['working_directory' => '/srv/foreign']);
    expect(fn () => ($this->captures)())->toThrow(ResourceOperationException::class)
        ->and($this->socket->sent)->toBe([]);
});
