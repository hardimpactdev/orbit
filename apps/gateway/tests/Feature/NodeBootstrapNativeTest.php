<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeBootstrapAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeBootstrapIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
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
    $socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-token']);
});

it('refuses an unavailable Gateway before recording bootstrap reads', function (): void {
    $this->gateway->update(['status' => LifecycleStatus::Failed]);
    expect(fn () => app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_probe'))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
});

it('captures stored public host keys for the official SSH pin policy', function (): void {
    $key = base64_encode(str_repeat('p', 32));
    $this->target->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $key]);
    $input = app(NodeBootstrapIntent::class)->input($this->target, 'node.bootstrap_probe');

    expect($input['target']['host_keys'])->toBe(['ssh-ed25519 '.$key]);
});

it('leaves first contact to the official SSH trust on first use policy', function (): void {
    $input = app(NodeBootstrapIntent::class)->input($this->target, 'node.bootstrap_probe');

    expect($input['target']['host_keys'])->toBe([]);
});

it('copies the probe SSH value to its fixed execution key and records names only', function (): void {
    config(['swamp.read_token' => 'fixture-read-token']);
    $identity = tmpfile();
    $value = "-----BEGIN OPENSSH PRIVATE KEY-----\n".bin2hex(random_bytes(32))."\n-----END OPENSSH PRIVATE KEY-----\n";
    fwrite($identity, $value);
    $path = stream_get_meta_data($identity)['uri'];
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAA');
    $keys->shouldReceive('privateKeyPath')->once()->andReturn($path);
    app()->instance(SshKeyProvider::class, $keys);

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
        expect($this->socket->sent[0])->toMatchArray(['type' => 'vault.put', 'payload' => [
            'vaultName' => 'orbit-control-inputs', 'key' => 'bootstrap-identity-node-'.$this->gateway->id, 'value' => $value, 'force' => true,
        ]]);
        $inputs = $this->socket->sent[1]['payload']['inputs'];
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
        expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
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
    $this->socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'code' => 'forbidden']];

    try {
        expect(fn () => app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.bootstrap_probe'))
            ->toThrow(ResourceOperationException::class);
        expect(Operation::query()->count())->toBe(0);
    } finally {
        fclose($identity);
    }
});

it('inspects a Mac from 209 with complete intent and projects its native check', function (?string $code): void {
    config(['swamp.read_token' => 'fixture.read.token']);
    $this->target->update(['platform' => 'macos', 'architecture' => 'arm64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'mac-public-key']);
    $identity = tmpfile();
    fwrite($identity, 'private-mac-bootstrap-sentinel');
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn(stream_get_meta_data($identity)['uri']);
    app()->instance(SshKeyProvider::class, $keys);
    try {
        $operation = app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.macos_inspect');
        expect($operation->node_id)->toBe($this->gateway->id);
        expect($operation->input['bootstrap'])->not->toHaveKeys(['artifacts', 'command', 'firewall']);
        expect($operation->input)->not->toHaveKeys(['bootstrap_identity', 'private_bindings']);
        expect($operation->input['bootstrap']['target']['host_keys'])->toBe(['ssh-ed25519 mac-public-key']);
        $definition = app(DirectOperation::class)->definition($operation->kind);
        expect($definition['workflow_inputs'])->toBeTrue()->and($definition['terminal_result']['native'])->toBeTrue();
        $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->run)->assertUnprocessable();
        $status = $code === null ? 'succeeded' : 'failed';
        $history = ['id' => $this->run, 'workflowName' => 'operation-node-macos-inspect', 'status' => $status,
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
            'startedAt' => now()->toISOString(), 'completedAt' => now()->toISOString(), 'jobs' => [['name' => 'bootstrap', 'steps' => [
                ['name' => 'Inspect the Mac', 'status' => 'succeeded', 'outputs' => ['architecture' => 'arm64', 'verified' => $code === null, 'error_code' => $code]],
                ['name' => 'Check the Mac', 'status' => $status],
            ]]]];
        $this->socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
        $projected = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $this->run], $definition['terminal_result']);
        expect($projected->status->value)->toBe($status)->and($projected->error_code)->toBe($code);
        expect($this->target->fresh()->user)->toBe('prior');
        expect(json_encode([$operation->input, $projected->result]))->not->toContain('private-mac-bootstrap-sentinel');
    } finally {
        fclose($identity);
    }
})->with([null, 'node.platform_mismatch', 'node.architecture_mismatch', 'node.macos_wireguard_required']);

it('refuses Mac inspection without admitted public host keys before any remote call', function (): void {
    $this->target->update(['platform' => 'macos', 'architecture' => 'arm64']);
    expect(fn () => app(AdmitNodeBootstrapAction::class)->execute($this->target, 'node.macos_inspect'))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
});
