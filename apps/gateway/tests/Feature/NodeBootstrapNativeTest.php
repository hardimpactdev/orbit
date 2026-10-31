<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeBootstrapAction;
use App\Actions\Nodes\StartNodePhpPackagesAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = Node::query()->create(['name' => 'bootstrap-gateway', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $this->gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->target = Node::query()->create(['name' => 'bootstrap-target', 'user' => 'prior', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.209', 'public_ssh_port' => 2222, 'wireguard_ip' => '10.44.0.209', 'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43), 'status' => LifecycleStatus::Active]);
    $this->target->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAA');
    $keys->shouldReceive('privateKeyPath')->andReturn('/private/bootstrap-key-sentinel');
    app()->instance(SshKeyProvider::class, $keys);
    $this->run = '0d363abd-ec96-4979-8866-f1c0233c576c';
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-token']);
});

it('admits first contact on its Gateway worker with exact target custody', function (): void {
    $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_verify');
    expect($operation->target_id)->toBe($this->target->id)->and($operation->node_id)->toBe($this->gateway->id);
    expect($operation->input['participant_node_ids'])->toBe([$this->gateway->id, $this->target->id]);
    expect($operation->input['private_bindings']['bootstrap_identity'])->toBe(['owner_node_id' => $this->target->id, 'read_node_ids' => [$this->gateway->id], 'write_node_ids' => []]);
    expect($this->target->fresh()->user)->toBe('prior');
    expect(json_encode($operation->toArray()))->not->toContain('/private/bootstrap-key-sentinel');
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->run)->assertOk()->assertJsonMissingPath('data.input.bootstrap_identity')->assertJsonPath('data.input.bootstrap.target.port', 2222);
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.bootstrap_identity', '/private/bootstrap-key-sentinel');
    $this->withServerVariables(['REMOTE_ADDR' => $this->target->wireguard_ip]);
    $this->getJson($path.'/private-material?run_id='.$this->run)->assertForbidden()->assertDontSee('/private/bootstrap-key-sentinel');
});

it('records native host observations without committing the candidate user or execution receipts', function (): void {
    $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_verify');
    $result = ['probed' => true, 'architecture' => 'x86_64', 'host_type' => 'ssh-ed25519', 'public_key' => 'AAAA', 'host_key_fingerprint' => $this->target->ssh_host_fingerprint, 'user' => 'candidate', 'bootstrap_identity' => 'discard'];
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z', 'result' => $result])->assertOk();
    expect($this->target->fresh()->user)->toBe('prior')->and($this->target->fresh()->ssh_host_key)->toBe('AAAA');
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->fresh()->result)->not->toHaveKeys(['user', 'bootstrap_identity']);
    expect($operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'child_intents']);
});

it('refuses a foreign target or credential owner before exposing the identity', function (string $change): void {
    $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_verify');
    $input = $operation->input;
    if ($change === 'target') {
        $input['bootstrap']['node_id'] = $this->gateway->id;
    } else {
        $input['private_bindings']['bootstrap_identity']['owner_node_id'] = $this->gateway->id;
    }
    $operation->update(['input' => $input]);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->run)->assertStatus(422)->assertDontSee('/private/bootstrap-key-sentinel');
})->with(['target', 'owner']);

it('refuses unavailable Gateway and unsupported bootstrap intents without recording work', function (): void {
    $this->gateway->update(['status' => LifecycleStatus::Failed]);
    expect(fn () => app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_verify'))->toThrow(ResourceOperationException::class);
    expect(fn () => app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.macos_inspect'))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
});

it('keeps PHP package preparation as one direct target with no child admission', function (): void {
    $operation = app(StartNodePhpPackagesAction::class)->execute($this->target, ['8.5']);
    expect($operation->kind)->toBe('node.prepare_php_packages')->and($operation->node_id)->toBe($this->target->id);
    expect($operation->input['packages']['extras'])->toBe('keep')->and($operation->input['packages']['fpm'])->toBe('none');
    expect(array_keys($operation->input['packages']['versions']))->toBe(['8.5']);
    expect($operation->input)->not->toHaveKeys(['parent_operation_id', '_php_packages_deadline']);
});

it('rejects malformed PHP version selection before recording an Operation', function (array $versions): void {
    expect(fn () => app(StartNodePhpPackagesAction::class)->execute($this->target, $versions))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
})->with([[[]], [['8.5', '8.5']], [['8.5;id']], [['99.1']]]);

it('admits the exact candidate account while preserving the stored prior identity', function (): void {
    $candidate = new ProvisionNodeData(name: $this->target->name, publicSshHost: $this->target->public_ssh_host,
        user: 'root', orbitUser: 'candidate', architecture: 'x86_64');
    $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_host', $candidate);
    expect($operation->input['bootstrap']['target']['user'])->toBe('root')
        ->and($operation->input['bootstrap']['managed_user'])->toBe('candidate')
        ->and($operation->input['bootstrap']['command']['user'])->toBe('candidate')
        ->and($this->target->fresh()->user)->toBe('prior')
        ->and($this->gateway->fresh()->user)->toBe('orbit');
});

it('records empty successful observations without an empty Node write', function (): void {
    $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_verify');
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->run,
        'status' => 'succeeded', 'started_at' => '2026-10-31T09:00:00.000Z', 'finished_at' => '2026-10-31T09:00:01.000Z',
        'result' => []])->assertOk();
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)->and($this->target->fresh()->user)->toBe('prior');
});

it('copies the probe SSH value to its fixed execution key and records names only', function (): void {
    $identity = tmpfile();
    $value = "-----BEGIN OPENSSH PRIVATE KEY-----\n".bin2hex(random_bytes(32))."\n-----END OPENSSH PRIVATE KEY-----\n";
    fwrite($identity, $value);
    $path = stream_get_meta_data($identity)['uri'];
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAA');
    $keys->shouldReceive('privateKeyPath')->once()->andReturn($path);
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(function (ProcessInvocation $call) use ($value): bool {
        expect($call->arguments[0])->toBe('/usr/local/bin/swamp');
        expect(array_slice($call->arguments, 1, 4))->toBe(['vault', 'put', 'orbit-control-inputs', 'bootstrap-identity-node-'.$this->gateway->id]);
        expect(stream_get_contents($call->protectedInput->stream()))->toBe($value);
        expect(json_encode($call->arguments))->not->toContain($value);

        return true;
    })->andReturn(new CommandResult(0, '{}', '', 1, false));
    app()->instance(ProcessRunner::class, $runner);

    try {
        $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_probe');
        expect($operation->input['execution_secrets'])->toBe(['bootstrap_identity' => [
            'vault' => 'orbit-control-inputs', 'key' => 'bootstrap-identity-node-'.$this->gateway->id,
        ]]);
        expect($operation->input)->not->toHaveKeys(['bootstrap_identity', 'private_bindings']);
        expect(json_encode($operation->toArray()))->not->toContain($value)->not->toContain($path);
        expect($this->target->fresh()->user)->toBe('prior');
        $definition = app(DirectOperation::class)->definition('node.bootstrap_probe');
        expect($definition['workflow_inputs'])->toBeTrue();
        expect($definition['terminal_result'])->toBe(['job' => 'bootstrap', 'step' => 'probe-host']);
        expect($definition)->not->toHaveKey('private_material');
        $inputs = $this->socket->sent[0]['payload']['inputs'];
        expect($inputs)->toBe([
            'intent' => ['bootstrap' => $operation->input['bootstrap']],
            'execution_secrets' => $operation->input['execution_secrets'],
            'operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $this->gateway->id,
        ]);
        expect(json_encode($inputs))->not->toContain($value)->not->toContain($path);
        $history = ['id' => $this->run, 'workflowName' => 'operation-node-bootstrap-probe', 'status' => 'succeeded',
            'inputs' => $inputs, 'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
            'jobs' => [['name' => 'bootstrap', 'steps' => [['name' => 'probe-host', 'outputs' => [
                'stdout' => json_encode(['probed' => true, 'architecture' => 'x86_64', 'host_type' => 'ssh-ed25519',
                    'public_key' => 'public-fixture', 'host_key_fingerprint' => $this->target->ssh_host_fingerprint]),
            ]]]]],
        ];
        $this->socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
        $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $this->run, 'status' => 'succeeded'], $definition['terminal_result']);
        expect($projected->status)->toBe(OperationStatus::Succeeded);
        expect($this->target->fresh()->ssh_host_key)->toBe('public-fixture')->and($this->target->fresh()->user)->toBe('prior');
        expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    } finally {
        fclose($identity);
    }
});

it('refuses the probe before recording work when its execution copy cannot be pushed', function (): void {
    $identity = tmpfile();
    fwrite($identity, 'synthetic-identity-'.bin2hex(random_bytes(32)));
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAA');
    $keys->shouldReceive('privateKeyPath')->andReturn(stream_get_meta_data($identity)['uri']);
    app()->instance(SshKeyProvider::class, $keys);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(new CommandResult(1, '', 'not persisted', 1, false));
    app()->instance(ProcessRunner::class, $runner);

    try {
        expect(fn () => app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_probe'))
            ->toThrow(ResourceOperationException::class);
        expect(Operation::query()->count())->toBe(0);
    } finally {
        fclose($identity);
    }
});
