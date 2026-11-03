<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeBootstrapAction;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\NodeRole;
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
