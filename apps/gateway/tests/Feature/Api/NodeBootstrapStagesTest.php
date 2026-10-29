<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeBootstrapOperationCompletion;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

/** @return array{Operation,Node} */
function bootstrapStagesParent(string $platform, bool $skip = false): array
{
    $node = FleetFixtures::node('stage-bootstrap-source', platform: $platform, managed: false);
    $node->update(['status' => 'provisioning', 'architecture' => null]);
    $names = ['probe', 'install', ...($platform === 'linux' ? ['host', 'verify'] : [])];
    $intents = array_map(static fn (string $name): array => ['key' => 'add-bootstrap-'.$name, 'kind' => 'node.bootstrap_'.$name, 'node_id' => $node->id, 'input' => []], $names);
    if ($platform === 'macos') {
        $intents[] = ['key' => 'add-macos-inspect', 'kind' => 'node.macos_inspect', 'node_id' => $node->id, 'input' => []];
    }
    $definition = ['executor' => 'swamp', 'execution' => NodeLifecycleOperationExecution::class, 'completion' => NodeWireGuardOperationCompletion::class,
        'runs' => [], 'children' => array_map(static fn (array $intent): array => ['kind' => $intent['kind'], 'node_from' => 'operation.node_id'], $intents),
        'completion_contract' => ['step' => 'unused', 'transport' => 'gateway', 'name' => 'unused', 'result' => []], 'result_fields' => ['error_code']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'node.add' => $definition]));
    $input = ['bootstrap_host' => '192.0.2.30', 'bootstrap_port' => 2222, 'bootstrap_user' => $platform === 'linux' ? 'root' : 'mini',
        'managed_user' => $platform === 'linux' ? 'orbit' : 'mini', 'enrollment_address' => $node->wireguard_ip,
        'bootstrap_fingerprint' => 'SHA256:'.str_repeat('A', 43), 'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id],
        'requested_architecture' => null, 'agent_pins' => [$platform.($platform === 'linux' ? '-x86_64' : '-aarch64') => str_repeat('a', 64)],
        'skip_bootstrap' => $skip, 'bootstrap_command' => ['user' => 'orbit', 'public_key' => 'ssh-ed25519 public-bootstrap', 'releases' => ['resolute'], 'packages' => ['openssh-server'], 'dns_policy' => 'preserve'],
        'bootstrap_firewall' => ['desired' => [], 'remove' => [], 'enable' => true],
        '_node_plan' => array_map(static fn (array $intent): array => ['type' => 'child', ...$intent, 'optional' => false], $intents)];
    $parent = app(StartOperationAction::class)->execute('node.add', 'node', $node->id, $node->id, $input, 'test', null);
    $parent->update(['state' => ['child_intents' => $intents]]);
    $children = app(OperationChildren::class);
    $probe = DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-probe']))['operation'];
    $result = ['probed' => true, 'architecture' => $platform === 'linux' ? 'x86_64' : 'arm64', 'host_type' => 'ssh-ed25519', 'public_key' => 'bootstrap-public',
        'host_key_fingerprint' => $input['bootstrap_fingerprint']];
    app(NodeBootstrapOperationCompletion::class)->complete($probe, OperationOutcome::succeeded($result));
    $probe->update(['status' => 'succeeded', 'result' => $result]);
    $installer = DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-install']))['operation'];
    $installer->update(['status' => 'succeeded', 'result' => ['installed' => true]]);

    return [$parent, $node->refresh()];
}

it('binds host setup and the subsequent managed login to distinct recorded accounts', function (bool $skip): void {
    [$parent, $node] = bootstrapStagesParent('linux', $skip);
    $children = app(OperationChildren::class);
    expect(fn () => DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-verify'])))
        ->toThrow(ResourceOperationException::class);
    $host = DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-host']))['operation'];
    expect($host->input['bootstrap_user'])->toBe('root');
    expect($host->input['_node_plan'])->toHaveCount($skip ? 0 : 1);
    if (! $skip) {
        expect($host->input['_node_plan'][0]['input']['command_input'])->toBe($parent->input['bootstrap_command']);
    }
    $host->update(['status' => 'succeeded', 'result' => ['node_plan_proven' => true, 'bootstrapped' => true]]);
    $node->update(['public_ssh_host' => '192.0.2.99']);
    $verify = DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-verify']))['operation'];
    expect($verify->input['bootstrap_user'])->toBe('orbit');
    expect($verify->input['bootstrap_host'])->toBe('192.0.2.30');
    expect(array_column($verify->input['_node_plan'], 'name'))->toBe(['agent.status', 'firewall.rules']);
    expect($verify->input['_node_plan'][1]['input']['command_input'])->toBe($parent->input['bootstrap_firewall']);
    expect($verify->lock_key)->toBeNull();
    expect($parent->fresh()->lock_key)->toBe('node:'.$node->id);
    expect(fn () => DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-verify', 'input' => ['bootstrap_user' => 'replacement']])))
        ->toThrow(ResourceOperationException::class);
})->with([false, true]);

it('binds Mac inspection to its existing account and tunnel without host setup', function (): void {
    [$parent, $node] = bootstrapStagesParent('macos');
    $child = DB::transaction(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'add-macos-inspect']))['operation'];

    expect($child->input['bootstrap_user'])->toBe('mini');
    expect($child->input['agent_pin'])->toBe(str_repeat('a', 64));
    expect(array_column($child->input['_node_plan'], 'name'))->toBe(['node.inspect-macos-enrollment']);
    expect($child->input['_node_plan'][0]['input']['command_input'])->toBe(['address' => $node->wireguard_ip]);
    expect($child->input['_node_plan'][0]['result'])->toBe(['architecture' => 'arm64']);
});

it('requires the admitted architecture platform and pin before accepting the managed login', function (string $mismatch): void {
    $gateway = FleetFixtures::node('bootstrap-check-gateway', [RoleName::Gateway]);
    $worker = FleetFixtures::node('bootstrap-check-worker');
    $worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    [$parent, $node] = bootstrapStagesParent('linux');
    $children = app(OperationChildren::class);
    $host = DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-host']))['operation'];
    $host->update(['status' => 'succeeded', 'result' => ['bootstrapped' => true]]);
    $verify = DB::transaction(fn () => $children->admit($parent, ['key' => 'add-bootstrap-verify']))['operation'];
    $parent->update(['status' => 'running']);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $verify->kind, 'operation_id' => $verify->id, 'worker' => 'verify'])->assertOk();
    $key = new HostKey('ssh-ed25519', 'bootstrap-public', $parent->input['bootstrap_fingerprint']);
    $scanner = Mockery::mock(HostKeyScanner::class);
    $scanner->shouldReceive('scan')->once()->with('192.0.2.30', 2222, null)->andReturn($key);
    app()->instance(HostKeyScanner::class, $scanner);
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('put')->once();
    $known->shouldReceive('path')->andReturn('/fixture/known-hosts');
    app()->instance(KnownHostsStore::class, $known);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/fixture/bootstrap-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $result = ['architecture' => 'x86_64', 'platform' => 'linux', 'sha256' => str_repeat('a', 64), 'version' => '1.0.0', 'observer' => 'ready'];
    if ($mismatch !== 'none') {
        $result[$mismatch] = 'wrong';
    }
    $runner = Mockery::mock(AdmissionAgentRunner::class);
    $runner->shouldReceive('runOver')->once()->withArgs(fn (Node $target, SshConnection $ssh, string $name, array $input): bool => $target->id === $node->id
        && $ssh->host === '192.0.2.30' && $ssh->port === 2222 && $ssh->user === 'orbit' && $name === 'agent.status' && $input === [])->andReturn($result);
    app()->instance(AdmissionAgentRunner::class, $runner);
    $step = $verify->input['_node_plan'][0];
    $body = ['worker' => 'verify', 'key' => $step['key'], 'transport' => $step['transport'], 'name' => $step['name'], 'input' => $step['input']];
    $response = $this->postJson('/api/v1/operation-executor/'.$verify->id.'/run', $body)->assertOk();
    if ($mismatch === 'none') {
        $response->assertJsonPath('data.sha256', str_repeat('a', 64));
    } else {
        $response->assertJsonPath('data.error_code', $mismatch === 'architecture' ? 'node.architecture_mismatch' : 'node.bootstrap_verification_failed');
        $this->postJson('/api/v1/operation-executor/'.$verify->id.'/run', [...$body, 'key' => 'bootstrap-firewall', 'name' => 'firewall.rules', 'input' => $verify->input['_node_plan'][1]['input']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    }
})->with(['none', 'architecture', 'platform', 'sha256']);
