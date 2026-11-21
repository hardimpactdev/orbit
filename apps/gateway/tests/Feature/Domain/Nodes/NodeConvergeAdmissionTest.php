<?php

declare(strict_types=1);

use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Actions\Nodes\AdmitNodeEnrollmentOperationAction;
use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Nodes\StartNodeConvergeAction;
use App\Data\Fleet\DesiredCliReleaseData;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Instances\Deployment\ContainerDeployOutcome;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\NestedFleetLayout;
use App\Domain\Instances\NestedFleetRefresh;
use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Nodes\GatewayPrivateDnsInput;
use App\Domain\Nodes\GatewayPrivateDnsState;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeConvergeScope;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\NodeNetworkReservation;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeUpdatePolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\NodeWorkAdmission;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Fleet\StaticCliRelease;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampConnection;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\GatewayRelease;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InstancePool;
use Tests\Support\ObservedReleaseInputs;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config(['instances.fleet_marker' => '/fixture/no-nested-fleet']);
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    config(['swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame): void {
        $this->frames[] = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function (): array {
        $frame = end($this->frames);
        if ($frame['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $frame['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-node-converge', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $this->manifest = tmpfile();
    fwrite($this->manifest, json_encode(['commit' => str_repeat('a', 40), 'version' => '1.2.3', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery(stream_get_meta_data($this->manifest)['uri'], '/unused'));
    $this->control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $this->control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create(['name' => 'target', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned']);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:pinned', 'agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();

        return $node;
    });
    $this->control->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->control->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/converge';
});

afterEach(function (): void {
    app(SwampConnection::class)->release();
    fclose($this->manifest);
});

it('captures base from recorded state, redacts its verifier and dispatches copies before exactly one workflow', function (): void {
    $role = $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'failed', 'error_code' => 'earlier']);
    $before = $role->refresh()->getAttributes();
    $response = $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    $capture = $operation->input['_converge'];
    expect(array_column($this->frames, 'type'))->toBe(['vault.put']);
    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);
    expect($capture['intent']['selected_roles'])->toBe([])
        ->and($capture['intent']['roles'])->toBe([])->and($capture['intent']['tools'])->toBe([])->and($capture['intent']['network'])->toBeNull()
        ->and($capture['intent']['agent'])->toMatchArray(['sha256' => str_repeat('b', 64), 'url' => 'https://artifacts.example/agent'])
        ->and($capture['intent']['kernel'])->toBe(['files' => [['path' => '/etc/modprobe.d/orbit-no-sctp.conf', 'content' => "install sctp /bin/false\n"]],
            'sysctl' => [['name' => 'net.ipv4.ip_nonlocal_bind', 'value' => '1', 'file' => '/etc/sysctl.d/60-orbit-nonlocal-bind.conf']]])
        ->and($capture['intent']['updates'])->toBe(NodeUpdatePolicy::for($this->node))->and($capture['intent']['updates']['daily'])->toBeTrue()
        ->and($capture['execution_secrets']['agent_verifier']['key'])->toBe('node-converge-operation-'.$operation->id.'-agent-verifier')
        ->and(array_column($this->frames, 'type'))->toBe(['vault.put', 'workflow.run'])
        ->and($this->frames[1]['payload']['workflowIdOrName'])->toBe('operation-node-converge')
        ->and($this->frames[1]['payload']['inputs']['intent'])->toBe($capture['intent'])
        ->and(json_encode($capture))->not->toContain(hash('sha256', 'private-test-agent-secret'))
        ->and($response->getContent())->not->toContain('private-test-agent-secret')->not->toContain('execution_secrets')
        ->and($role->refresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('captures no CLI plan or release dependency for a Node without a CI caller', function (?RoleName $role): void {
    if ($role !== null) {
        $this->node->roles()->create(['role' => $role, 'status' => 'active']);
    }
    $this->mock(CliReleaseCatalog::class, fn ($mock) => $mock->shouldNotReceive('find'));
    $this->mock(ReleaseHistory::class, fn ($mock) => $mock->shouldNotReceive('commit', 'count', 'ancestors', 'unchanged'));

    $this->postJson($this->url, ['base' => true])->assertAccepted();

    expect(Operation::query()->sole()->input['_converge']['intent']['cli'])->toBeNull();
    $rollout = app(CliConvergePlan::class)->fleetInput($this->node, observer: true);
    expect($rollout['release'])->toBeNull()->and($rollout['profile'])->toBe('')
        ->and($rollout['ssh']['target']['host'])->toBe($this->node->wireguard_ip);
    Http::assertNothingSent();
    Process::assertNothingRan();
})->with(['roleless' => [null], 'workload' => [RoleName::AppProd], 'services' => [RoleName::Database],
    'app-dev without proof runner' => [RoleName::AppDev], 'Swamp' => [RoleName::Swamp], 'Gateway' => [RoleName::Gateway]]);

it('retires CLI intent when the CI runner assignment is being removed', function (): void {
    $this->node->roles()->create(['role' => RoleName::CiRunner, 'status' => 'removing']);

    $this->postJson($this->url, ['base' => true])->assertAccepted();

    expect(Operation::query()->sole()->input['_converge']['intent']['cli'])->toBeNull();
});

it('keeps the CI caller CLI plan even for base-only scope', function (): void {
    $this->node->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    $manifest = tmpfile();
    try {
        fwrite($manifest, json_encode(['number' => 9001, 'checksums_url' => 'https://example.test/SHA256SUMS',
            'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://example.test/orbit', 'sha256' => str_repeat('c', 64)]]], JSON_THROW_ON_ERROR));
        $release = new StaticCliRelease(stream_get_meta_data($manifest)['uri']);
        app()->instance(ReleaseHistory::class, $release);
        app()->instance(CliReleaseCatalog::class, $release);
        config(['app.version' => str_repeat('c', 40)]);
        $this->postJson($this->url, ['base' => true])->assertAccepted();
        $cli = Operation::query()->sole()->input['_converge']['intent']['cli'];
        expect($cli['skip_reason'])->toBeNull()->and($cli['plan_error'])->toBeNull()
            ->and($cli['release'])->toMatchArray(['url' => 'https://example.test/orbit', 'sha256' => str_repeat('c', 64)])
            ->and($cli['profile'])->toContain('10.44.0.2');
        Http::assertNothingSent();
        Process::assertNothingRan();
    } finally {
        fclose($manifest);
    }
});

it('replays an idempotent start without recapturing private input or dispatching again', function (): void {
    $first = $this->withHeader('Idempotency-Key', 'node-converge-replay')->postJson($this->url, ['base' => true])->assertAccepted();
    $frames = count($this->frames);
    $again = $this->postJson($this->url, ['base' => true])->assertAccepted();
    expect($again->json('data.id'))->toBe($first->json('data.id'))->and(count($this->frames))->toBe($frames)->and(Operation::query()->count())->toBe(1);
});

it('rejects invalid scope and removed flags before any operation or private copy', function (array $input): void {
    $this->postJson($this->url, $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->count())->toBe(0)->and($this->frames)->toBe([]);
})->with([
    'conflicting' => [['base' => true, 'roles' => ['app-prod']]],
    'empty' => [['roles' => []]], 'unassigned' => [['roles' => ['app-prod']]], 'unknown' => [['roles' => ['unknown']]],
    'force' => [['force' => true]], 'full not a boolean' => [['full' => 'yes']], 'worker credentials' => [['worker_credentials' => true]],
    'duplicate' => [['roles' => ['app-prod', 'app-prod']]],
]);

it('requires Node access before admitting work', function (): void {
    $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'no-access', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->postJson($this->url, ['base' => true])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->count())->toBe(0)->and($this->frames)->toBe([]);
});

it('refuses unsupported Nodes from recorded eligibility with no live probe', function (array $attributes): void {
    Node::withoutEvents(fn () => $this->node->forceFill($attributes)->save());
    $this->postJson($this->url, ['base' => true])->assertUnprocessable()->assertJsonPath('error.code', 'node.converge_unsupported');
    expect(Operation::query()->count())->toBe(0)->and($this->frames)->toBe([]);
})->with([
    'inactive' => [['status' => 'failed']], 'macOS' => [['platform' => 'macos']], 'unmanaged' => [['ssh_host_fingerprint' => null]],
    'missing verifier' => [['agent_secret_hash' => null]], 'empty verifier' => [['agent_secret_hash' => '']],
    'short verifier' => [['agent_secret_hash' => str_repeat('a', 63)]], 'uppercase verifier' => [['agent_secret_hash' => str_repeat('A', 64)]],
    'nonhex verifier' => [['agent_secret_hash' => str_repeat('g', 64)]], 'linebreak verifier' => [['agent_secret_hash' => str_repeat('a', 64)."\n"]],
]);

it('records the shared hub and the base mark; a converge of the same Node and a non-base hold of the hub still refuse', function (): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['_converge']['network'])->toBe(['hub_id' => $this->control->id, 'base' => true]);
    expect(fn () => app(NodeNetworkReservation::class)->hold($this->control, false, fn () => null))->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
    $this->postJson($this->url, ['base' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
});

/** A second converge target beside `target`, with its own verifier, reachable by the control Node. */
function otherConvergeNode(Node $node, Node $control): Node
{
    $other = $node->replicate(['instance_bridge']);
    $other->forceFill(['name' => 'other', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '10.44.0.8'])->saveQuietly();
    $control->accessibleNodes()->attach($other);

    return $other;
}

it('admits two base converges of different Nodes at once, and a base hold of the hub beside them', function (): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $other = otherConvergeNode($this->node, $this->control);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $this->postJson('/api/v1/nodes/'.$other->id.'/converge', ['base' => true])->assertAccepted();
    $admitted = app(NodeNetworkReservation::class)->hold($this->control, true, fn () => Operation::query()->latest('id')->first());

    expect(Operation::query()->count())->toBe(2)->and($admitted->target_id)->toBe($other->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('admits converges of two peers of the hub side by side, whatever their scope, in both orders', function (bool $baseFirst): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $other = otherConvergeNode($this->node, $this->control);
    $other->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $roles = '/api/v1/nodes/'.$other->id.'/converge';
    $this->postJson($baseFirst ? $this->url : $roles, $baseFirst ? ['base' => true] : ['roles' => ['app-prod']])->assertAccepted();

    $this->postJson($baseFirst ? $roles : $this->url, $baseFirst ? ['roles' => ['app-prod']] : ['base' => true])->assertAccepted();
    expect(Operation::query()->count())->toBe(2);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
})->with(['base first' => true, 'roles first' => false]);

it('refuses a converge of the hub beside a peer converge with roles, and a peer converge with roles beside the hub\'s base converge', function (bool $hubFirst): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    if ($hubFirst) {
        // A base converge of the hub Node itself.
        $this->postJson($this->url, ['base' => true])->assertAccepted();
        Operation::query()->sole()->forceFill(['node_id' => $this->control->id, 'target_id' => $this->control->id])->save();
        $other = otherConvergeNode($this->node, $this->control);
        $other->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $this->postJson('/api/v1/nodes/'.$other->id.'/converge', ['roles' => ['app-prod']])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    } else {
        $other = otherConvergeNode($this->node, $this->control);
        $other->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $this->postJson('/api/v1/nodes/'.$other->id.'/converge', ['roles' => ['app-prod']])->assertAccepted();
        expect(fn () => app(NodeNetworkReservation::class)->hold($this->control, true, fn () => null))->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
    }
    expect(Operation::query()->count())->toBe(1);
})->with(['hub first' => true, 'peer first' => false]);

it('treats an open converge without the base mark as one with roles', function (): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $open = Operation::query()->sole();
    $open->forceFill(['input' => [...$open->input, '_converge' => [...$open->input['_converge'], 'network' => ['hub_id' => $this->control->id]]]])->save();

    expect(fn () => app(NodeNetworkReservation::class)->hold($this->control, true, fn () => null))->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
});

it('builds a base intent without a network for the hub Node and for a Node it serves, so base converges never touch the hub', function (): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $this->control->forceFill(['agent_secret_hash' => hash('sha256', 'hub-secret'), 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned'])->saveQuietly();

    expect(app(NodeDesiredConvergeInput::class)->baseIntent($this->control)['network'])->toBeNull()
        ->and(app(NodeDesiredConvergeInput::class)->baseIntent($this->node)['network'])->toBeNull();
});

it('refuses app-dev role convergence while Node convergence holds the Node', function (bool $timedOut): void {
    $this->node->update(['tld' => 'target.test']);
    $role = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    if ($timedOut) {
        $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out']);
    }
    $before = $role->refresh()->getAttributes();

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.target_busy'));

    expect(Operation::query()->count())->toBe(1)->and($role->refresh()->getAttributes())->toBe($before);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
})->with(['queued' => false, 'timed out with native hold' => true]);

it('refuses Node convergence while app-dev role convergence holds the Node', function (): void {
    $this->node->update(['tld' => 'target.test']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true);

    $this->postJson($this->url, ['base' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');

    expect(Operation::query()->count())->toBe(1);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

/** Admit the real enrollment snapshot, including its app-dev role work, with a local bootstrap-key fixture. */
function admitLockTestEnrollment(Node $node, Node $control): Operation
{
    $control->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned',
        'ssh_host_fingerprint' => 'SHA256:pinned', 'wireguard_public_key' => base64_encode(str_repeat('h', 32))])->saveQuietly();
    $control->roles()->firstOrCreate(['role' => RoleName::Vpn], ['status' => 'active']);
    $folder = sys_get_temp_dir().'/orbit-admission-lock-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($folder.'/wireguard');
    file_put_contents($folder.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $folder]);
    test()->beforeApplicationDestroyed(static fn () => File::deleteDirectory($folder));
    $key = tmpfile();
    fwrite($key, 'fixture-bootstrap-key');
    test()->mock(SshKeyProvider::class, function ($mock) use ($key): void {
        $mock->shouldReceive('privateKeyPath')->andReturn(stream_get_meta_data($key)['uri']);
        $mock->shouldReceive('publicKey')->andReturn(config('swamp.ssh_public_key'));
    });
    try {
        return app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
            name: $node->name, publicSshHost: $node->public_ssh_host, roles: [RoleName::AppDev]));
    } finally {
        fclose($key);
    }
}

it('refuses node.add role admission while Node convergence holds the Node', function (): void {
    $this->node->update(['tld' => 'target.test']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->postJson($this->url, ['base' => true])->assertAccepted();

    expect(fn () => admitLockTestEnrollment($this->node, $this->control))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.target_busy'));

    expect(Operation::query()->count())->toBe(1);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('keeps enrollment role work under its parent hold and refuses another Node admission', function (bool $role): void {
    $this->node->update(['tld' => 'target.test']);
    $assignment = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $operation = admitLockTestEnrollment($this->node, $this->control);

    expect($operation->kind)->toBe('node.add')
        ->and($operation->input['intent']['roles'][0]['kind'])->toBe('node.role_converge')
        ->and($operation->input['_node_enrollment']['role_snapshots'][0]['assignment_id'])->toBe($assignment->id)
        ->and(Operation::query()->count())->toBe(1);
    expect(fn () => $role
        ? app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true)
        : app(StartNodeConvergeAction::class)->execute($this->node, true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.target_busy'));

    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
})->with(['Node convergence' => false, 'role convergence' => true]);

it('allows app-dev role convergence on a distinct Node during Node convergence', function (): void {
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $other = $this->node->replicate(['instance_bridge']);
    $other->forceFill(['name' => 'other', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '10.44.0.8', 'tld' => 'other.test'])->saveQuietly();
    $other->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);

    $operation = app(AdmitNodeRoleOperationAction::class)->add($other, RoleName::AppDev, true);

    expect($operation->target_id)->toBe($other->id)->and(Operation::query()->count())->toBe(2);
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('keeps convergence excluded while agent rekey holds the Node', function (): void {
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id])->assertSuccessful();

    $this->postJson($this->url, ['base' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');

    expect(Operation::query()->sole()->kind)->toBe('node.agent_rekey');
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('keeps agent rekey excluded while convergence holds the Node', function (): void {
    $this->postJson($this->url, ['base' => true])->assertAccepted();

    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id])->assertFailed();

    expect(Operation::query()->sole()->kind)->toBe('node.converge');
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('admits a fresh attempt after failure without trusting an earlier footprint digest', function (): void {
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $operation = Operation::query()->sole();
    $operation->update(['status' => OperationStatus::Failed, 'lock_key' => null]);
    $second = app(StartNodeConvergeAction::class)->execute($this->node, true);
    expect($second->id)->not->toBe($operation->id)->and($second->input['_converge']['intent']['agent'])->toBe($operation->input['_converge']['intent']['agent']);
});

it('selects only existing assignments without changing their status or settings', function (): void {
    $a = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $b = $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => 'failed']);
    expect(NodeConvergeScope::roles($this->node, false, null))->toBe(['app-dev', 'metrics'])
        ->and(NodeConvergeScope::roles($this->node, false, ['metrics']))->toBe(['metrics'])
        ->and(NodeConvergeScope::roles($this->node, true, null))->toBe([])
        ->and($a->refresh()->status->value)->toBe('active')->and($b->refresh()->status->value)->toBe('failed');
});

it('allocates every Instance host app-dev Node\'s bridge in the admission, before the read-only capture', function (array $scope, ?string $bridge): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    InstancePool::give($this->node);
    // The capture records what it sees and stops the admission there; the test needs nothing after it.
    $seen = new ArrayObject;
    app()->instance(NodeDesiredConvergeInput::class, new class($seen)
    {
        public function __construct(private ArrayObject $seen) {}

        public function capture(Node $node): never
        {
            $this->seen['bridge'] = Node::query()->whereKey($node->id)->value('instance_bridge');

            throw new RuntimeException('capture reached');
        }
    });

    $this->postJson($this->url, $scope);

    expect($seen['bridge'])->toBe($bridge);
})->with([
    'the app-dev role' => [['roles' => ['app-dev']], '10.253.0.0/24'],
    'every role' => [[], '10.253.0.0/24'],
    'the base only' => [['base' => true], null],
]);

it('allocates the bridge of a new app-dev Node with the Instance host opt-in and no runtime in its first converge admission', function (): void {
    // A new Incus host: the operator's opt-in is its only signal, before any runtime or bridge health exists.
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    Node::query()->whereKey($this->node->id)->update(['settings' => ['instance_host' => true]]);
    $seen = new ArrayObject;
    app()->instance(NodeDesiredConvergeInput::class, new class($seen)
    {
        public function __construct(private ArrayObject $seen) {}

        public function capture(Node $node): never
        {
            $this->seen['bridge'] = Node::query()->whereKey($node->id)->value('instance_bridge');
            $this->seen['intent'] = app(InstanceHostIntent::class)->for($node);

            throw new RuntimeException('capture reached');
        }
    });

    $this->postJson($this->url, []);

    expect(InstanceRuntime::query()->where('node_id', $this->node->id)->exists())->toBeFalse()
        ->and($seen['bridge'])->toBe('10.253.0.0/24')
        ->and($seen['intent']['cidr'])->toBe('10.253.0.0/24')
        ->and($seen['intent']['pool'])->toBe('orbit-instances')
        ->and($seen['intent'])->not->toHaveKey('create_pool');
});

it('releases an unused Instance bridge of an app-dev Node without the Instance pool in the admission, before the capture', function (): void {
    // As release 2158 left the live services Node: app-dev beside other roles, no Incus, and a bridge from an earlier admission.
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    Node::query()->whereKey($this->node->id)->update(['instance_bridge' => '10.253.4.0/24']);
    $seen = new ArrayObject;
    app()->instance(NodeDesiredConvergeInput::class, new class($seen)
    {
        public function __construct(private ArrayObject $seen) {}

        public function capture(Node $node): never
        {
            $this->seen['bridge'] = Node::query()->whereKey($node->id)->value('instance_bridge');

            throw new RuntimeException('capture reached');
        }
    });

    $this->postJson($this->url, []);

    expect($seen->getArrayCopy())->toHaveKey('bridge')->and($seen['bridge'])->toBeNull();
});

it('claims local DNS before dispatch and retains cleanup after a later machine failure or crash', function (bool $direct, bool $crash): void {
    Node::withoutEvents(fn () => $this->control->forceFill(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned',
        'ssh_host_fingerprint' => 'SHA256:pinned', 'agent_secret_hash' => hash('sha256', 'private-control-secret')])->save());
    $state = app(GatewayPrivateDnsState::class);
    expect($state->owned($this->control->id))->toBeFalse();
    $operation = $direct
        ? app(AdmitNodeRoleOperationAction::class)->add($this->control, RoleName::Gateway, converge: true)
        : app(StartNodeConvergeAction::class)->execute($this->control, roles: ['gateway']);
    // No machine job has run. The durable claim covers an interrupted or partially installed listener.
    expect($state->owned($this->control->id))->toBeTrue()
        ->and(array_column($this->frames, 'type'))->not->toContain('workflow.run');
    $runtime = $direct ? $operation->input['native_role']['runtime'] : $operation->input['_converge']['intent']['roles'][0]['runtime'];
    expect($runtime['gateway_dns'])->toHaveKey('listener');
    if (! $crash) {
        $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'dns-machine-failed']]);
        if ($direct) {
            app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => 'dns-machine-failed', 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'error_code' => 'operation.native_failed', 'error_message' => 'PHP-FPM validation failed after DNS installation.',
                'result' => ['steps' => ['Apply the role on the Node' => ['status' => 'failed']],
                    'summary' => ['failed_steps' => ['Apply the role on the Node']]]]);
        } else {
            $phases = [];
            foreach (['input', 'agent', 'software', 'roles', 'tools', 'network', 'check'] as $index => $name) {
                $phases[] = ['name' => $name, 'status' => $index < 3 ? 'succeeded' : ($index === 3 ? 'failed' : 'skipped'), 'changed' => 0, 'elapsed_seconds' => 0];
            }
            app(NodeConvergeProjection::class)->apply($operation, ['run_id' => 'dns-machine-failed', 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
                'result' => ['outcome' => 'failed', 'phases' => $phases, 'changed' => 0, 'elapsed_seconds' => 0, 'tools' => [], 'browser_trust' => [], 'digests' => [],
                    'cleanup_complete' => true, 'failed_phase' => 'roles', 'error_code' => 'operation.native_failed']]);
        }
        expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    }
    expect($state->owned($this->control->id))->toBeTrue();
    $this->node->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $withdraw = app(GatewayPrivateDnsInput::class)->capture($this->control);
    expect($withdraw)->toHaveKey('withdraw')->not->toHaveKey('listener')
        ->and($withdraw['withdraw']['resolver_address'])->toBe($this->node->wireguard_ip);
    // Removal also works before any successful withdrawal or terminal receipt arrives.
    $assignment = $this->control->roles()->where('role', RoleName::Gateway)->firstOrFail();
    $assignment->update(['status' => 'removing']);
    $removal = app(NativeNodeRoleRemovalInput::class);
    $plan = $removal->capture($this->control, $assignment, $removal->publications($this->control, RoleName::Gateway));
    expect(array_column(array_column($plan['stop_units'], 'input'), 'unit'))->toContain('orbit-private-dns.socket', 'orbit-private-dns.service');
    expect(array_column($plan['files'], 'path'))->toContain('/etc/systemd/system/orbit-private-dns.socket', '/etc/systemd/system/orbit-private-dns.service');
})->with(['Node converge' => [false], 'role converge' => [true]])
    ->with(['later machine failure' => [false], 'process crash without receipt' => [true]]);

it('bounds the roles phase of an Instance fleet Node\'s app-dev converge at 1800 s and leaves every other converge at the workflow\'s 900 s', function (): void {
    // docs/reference/development-containers.md#instance-host-in-the-fleet: the first Instance host build includes the stock and Instance images.
    $marker = sys_get_temp_dir().'/orbit-instance-fleet-converge-'.getmypid();
    config(['instances.fleet_marker' => $marker]);
    Node::query()->whereKey($this->node->id)->update(['public_ssh_host' => NestedFleetLayout::Layout['app-dev']['address']]);
    $intent = fn (array $roles): array => new ReflectionMethod(NodeDesiredConvergeInput::class, 'intent')
        ->invoke(app(NodeDesiredConvergeInput::class), $this->node->refresh(), [], $roles, false, null, [], null);
    expect($intent(['app-dev']))->not->toHaveKey('roles_seconds');
    touch($marker);
    try {
        expect($intent(['app-dev'])['roles_seconds'])->toBe(1800)
            ->and($intent(['app-prod']))->not->toHaveKey('roles_seconds')
            ->and($intent([]))->not->toHaveKey('roles_seconds');
    } finally {
        unlink($marker);
    }
});

it('sends the recorded phase digests and clears the record', function (bool $full): void {
    // docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change
    $recorded = ['agent' => str_repeat('a', 64), 'roles' => str_repeat('b', 64)];
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode($recorded), 'converge_digests_full' => true]);

    $this->postJson($this->url, ['base' => true, 'full' => $full])->assertAccepted();
    $first = Operation::query()->sole();
    $intent = $first->input['_converge']['intent'];
    expect($intent['recorded_digests'])->toBe($full ? [] : $recorded)
        ->and($first->input['_converge']['held_digests'])->toBe($recorded)
        ->and($this->node->refresh()->converge_digests)->toBeNull();

    $first->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $second = Operation::query()->whereKeyNot($first->id)->sole();
    expect($second->input['_converge']['intent']['recorded_digests'])->toBe([]);
})->with(['recorded' => false, 'full' => true]);

it('holds only a full-scope converge record for a scoped converge', function (array $scope, bool $full, bool $held): void {
    // docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change: a role Operation's record (prerequisites, tools, node,
    // firewall) is never restored by a scoped converge, which could skip a phase that switches a service back.
    $recorded = $full ? ['agent' => str_repeat('a', 64), 'tools' => str_repeat('d', 64)] : ['prerequisites' => str_repeat('a', 64), 'tools' => str_repeat('d', 64)];
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode($recorded), 'converge_digests_full' => $full]);

    $this->postJson($this->url, $scope)->assertAccepted();

    $capture = Operation::query()->sole()->input['_converge'];
    expect($capture['held_digests'] ?? null)->toBe($held ? $recorded : ($scope === [] ? null : []))
        ->and($this->node->refresh()->converge_digests_full)->toBeFalse();
})->with([
    'full scope' => [[], true, false],
    'base after a full converge' => [['base' => true], true, true],
    'base after a role Operation' => [['base' => true], false, false],
]);

it('compares with no recorded digest while the Node records a failed agent pin', function (): void {
    // An agent command records the pin outside any admission lock; the converge that repairs it must run the agent phase.
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode(['agent' => str_repeat('a', 64)]), 'agent_failed_pin' => str_repeat('c', 64)]);

    $this->postJson($this->url, ['base' => true])->assertAccepted();

    expect(Operation::query()->sole()->input['_converge']['intent']['recorded_digests'])->toBe([]);
});

it('clears the recorded phase digests when another Operation takes the Node admission lock', function (): void {
    $this->node->update(['tld' => 'target.test']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode(['agent' => str_repeat('a', 64)])]);

    app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true);

    expect($this->node->refresh()->converge_digests)->toBeNull();
});

it('sends the recorded phase digests to a role convergence, none with full, and clears the record', function (bool $full): void {
    // docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change: one record for converge and role Operations.
    $this->node->update(['tld' => 'target.test']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $recorded = ['prerequisites' => str_repeat('a', 64), 'node' => str_repeat('b', 64)];
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode($recorded)]);

    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true, full: $full);

    expect($operation->input['native_role']['recorded_digests'])->toBe($full ? [] : $recorded)
        ->and($this->node->refresh()->converge_digests)->toBeNull();
})->with(['recorded' => false, 'full' => true]);

it('takes full from the role convergence request', function (): void {
    $this->node->update(['tld' => 'target.test']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode(['node' => str_repeat('a', 64)])]);

    $this->postJson('/api/v1/nodes/'.$this->node->id.'/roles', ['role' => 'app-dev', 'converge_existing' => true, 'full' => true])->assertAccepted();

    expect(Operation::query()->sole()->input['native_role']['recorded_digests'])->toBe([]);
});

it('limits an automatic converge to active selected roles under the Node lock', function (): void {
    $this->node->forceFill(['converge_digests' => ['roles' => str_repeat('1', 64)], 'converge_digests_full' => true])->save();
    $role = $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);

    $operation = app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod'], release: true);

    $capture = $operation->input['_converge'];
    expect($operation->lock_key)->toBe('node:'.$this->node->id)
        ->and($capture['intent']['release_roles_only'])->toBeTrue()
        ->and($capture['intent']['recorded_digests'])->toBe([])
        ->and($capture['held_digests'])->toBe(['roles' => str_repeat('1', 64)])
        ->and($capture['intent']['selected_roles'])->toBe(['app-prod'])
        ->and($capture['intent']['role_units'])->toBe([])
        ->and($capture['intent']['tools'])->toBe([])
        ->and($capture['intent']['network'])->toBeNull()
        ->and(array_keys($capture['release_inputs']['roles']))->toBe([$role->id]);
});

it('serializes automatic role admission with participating work in both admission orders', function (string $kind): void {
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $converge = fn (): Operation => app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod'], release: true);
    $admit = fn (array $ids): Operation => app(OperationStore::class)->create($kind, 'instance', 99, $this->control->id,
        ['participant_node_ids' => $ids], 'test', null);
    $participants = [$this->control->id, $this->node->id];

    $busy = $admit($participants);
    expect($converge)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->count())->toBe(1);
    $expired = ['swamp_run_id' => 'still-running', 'native_timeout' => [], 'native_cancel_requested_at' => now()->toISOString()];
    $busy->update(['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'state' => $expired]);
    expect($converge)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    $busy->update(['state' => [...$expired, 'native_cancel_confirmed_at' => now()->toISOString()]]);

    $scope = $converge();
    if ($kind === 'instance.destroy') {
        // A removal is accepted at once beside a running automatic converge (docs/reference/node-provisioning.md).
        expect($admit($participants)->status)->toBe(OperationStatus::Queued)->and(Operation::query()->count())->toBe(3);
        $scope->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);

        return;
    }
    expect(fn () => $admit($participants))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect($admit([$this->control->id])->status)->toBe(OperationStatus::Queued)
        ->and(Operation::query()->count())->toBe(3);
    $scope->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    expect($admit($participants)->status)->toBe(OperationStatus::Queued);
})->with(['instance.deploy', 'instance.destroy']);

it('keeps the release pending on a deferred T3 receipt and schedules a new role Operation after the retry interval', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($this->node)]])->save();
    $operation = app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod'], release: true);
    $operation->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now(),
        'result' => ['t3_restart_deferred' => ['orbit-instances/instance-7']]]);
    $commit = str_repeat('a', 40);
    config(['app.version' => $commit, 'orbit.gateway_checkout' => base_path()]);
    $release = GatewayRelease::query()->create(['sha' => $commit, 'trigger' => 'auto', 'outcome' => 'verified', 'duration_ms' => 0,
        'phases' => ['fleet' => ['status' => 'pending', 'nodes' => [$this->node->id => ['node_id' => $this->node->id,
            'name' => $this->node->name, 'status' => 'running', 'role_operation_id' => $operation->id]]]]]);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(FleetReleaseLag::RetryAfterSeconds)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['status'])->toBe('pending')
        ->and(Operation::query()->count())->toBe(1);
    $this->travel(FleetReleaseLag::RetryAfterSeconds)->seconds();
    expect($fleet->execute($release))->toBe(10);
    $retry = Operation::query()->latest('id')->firstOrFail();
    expect($retry->id)->not->toBe($operation->id)
        ->and($retry->kind)->toBe('node.converge')
        ->and($retry->input['_converge']['intent']['selected_roles'])->toBe(['app-prod']);
});

/** A verified release whose fleet pass started now and holds one row for the target Node. */
function releaseWithRow(array $row): GatewayRelease
{
    $commit = str_repeat('a', 40);
    config(['app.version' => $commit, 'orbit.gateway_checkout' => base_path()]);

    return GatewayRelease::query()->create(['sha' => $commit, 'trigger' => 'auto', 'outcome' => 'verified', 'duration_ms' => 0,
        'phases' => ['fleet' => ['status' => 'pending', 'started_at' => now()->toISOString(), 'nodes' => [test()->node->id => ['node_id' => test()->node->id,
            'name' => test()->node->name, ...$row]]]]]);
}

/** A second managed Node with an active app-prod role whose agent is current. */
function nextReleaseNode(): Node
{
    $next = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'next', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned']));
    $next->forceFill(['ssh_host_fingerprint' => 'SHA256:pinned', 'agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();
    $next->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $next->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($next)]])->save();

    return $next;
}

function prepareReleaseTarget(): void
{
    test()->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    test()->node->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin(test()->node)]])->save();
}

it('queues a busy-refused release role converge ahead of new work, lets the holder finish, and admits it once the holder ends', function (): void {
    prepareReleaseTarget();
    $admit = fn (string $kind, int $instance, array $input = []): Operation => app(OperationStore::class)->create($kind, 'instance', $instance,
        $this->node->id, ['participant_node_ids' => [$this->node->id], ...$input], 'test', null);
    $holder = $admit('instance.create', 99);
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(15)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['status'])->toBe('pending');
    // New work waits behind the converge, on another target and on the holder's own target alike.
    foreach ([100, 99] as $instance) {
        expect(fn () => $admit('instance.deploy', $instance))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    }
    // Only the holder's own continuation goes on: the create hands off to its completion, which holds the Node in turn.
    $holder->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    expect(fn () => $admit('instance.deploy', 99))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    $completion = $admit('instance.complete', 99, ['create_operation_id' => $holder->id]);
    expect($fleet->execute($release))->toBe(15);
    $completion->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    expect($fleet->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($release->refresh()->phases['fleet']['nodes'][$this->node->id]['role_operation_id'])->toBe($converge->id);
    $converge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    // Once admitted, the converge no longer holds new work back.
    expect($admit('instance.deploy', 100)->status)->toBe(OperationStatus::Queued);
});

it('admits a release role converge beside a running task copy turn, and keeps admitting task work while it runs', function (): void {
    prepareReleaseTarget();
    $task = fn (string $kind): Operation => app(OperationStore::class)->create($kind, 'task_group', 77, $this->node->id, [], 'test', null);
    $turn = $task('tasks.copy_turn');
    $release = releaseWithRow(['status' => 'pending']);

    expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($release->refresh()->phases['fleet']['nodes'][$this->node->id]['role_operation_id'])->toBe($converge->id)
        ->and($turn->refresh()->status)->toBe(OperationStatus::Queued)
        // A copy turn, check or cancel runs in its copy beside the converge.
        ->and($task('tasks.check')->status)->toBe(OperationStatus::Queued);
});

it('bounds a queued role converge: removals pass the wait, and after the wait limit it is admitted beside its holders', function (): void {
    prepareReleaseTarget();
    $admit = fn (string $kind, int $instance): Operation => app(OperationStore::class)->create($kind, 'instance', $instance, $this->node->id, [], 'test', null);
    $holder = $admit('instance.deploy', 99);
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(15)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['waiting_for'])->toBe([$holder->id])
        ->and(fn () => $admit('instance.deploy', 100))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'))
        // A destroy frees the Node, so the wait never refuses it; the destroy then holds the converge like any Instance work.
        ->and($admit('instance.destroy', 101)->status)->toBe(OperationStatus::Queued);

    // The pass retries every 15 s and renews the wait; the holder never ends, yet the wait ends at its limit.
    $delays = [];
    for ($waited = 15; $waited <= NodeWorkAdmission::WaitLimitSeconds; $waited += 15) {
        $this->travel(15)->seconds();
        $delays[] = $fleet->execute($release);
    }

    expect(array_pop($delays))->toBe(10)
        ->and(array_unique($delays))->toBe([15]);
    $row = $release->refresh()->phases['fleet']['nodes'][$this->node->id];
    expect($row['role_operation_id'])->toBe(Operation::query()->where('kind', 'node.converge')->sole()->id)
        ->and($row)->not->toHaveKey('waiting_for')
        ->and($holder->refresh()->status)->toBe(OperationStatus::Queued);
});

it('lets every continuation of a holder the converge was admitted beside finish while it runs, and holds new work back', function (): void {
    prepareReleaseTarget();
    $store = app(OperationStore::class);
    $admit = fn (string $kind, int $instance, array $input = []): Operation => $store->create($kind, 'instance', $instance, $this->node->id, $input, 'test', null);
    $create = $admit('instance.create', 99);
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);
    for ($waited = 0; $waited < NodeWorkAdmission::WaitLimitSeconds; $waited += 15) {
        expect($fleet->execute($release))->toBe(15);
        $this->travel(15)->seconds();
    }
    expect($fleet->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($converge->state['admitted_beside'])->toBe([$create->id]);

    $create->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    // The completion names the create; a step after the completion names the completion. Both go on beside the running converge.
    $complete = $admit('instance.complete', 99, ['create_operation_id' => $create->id]);
    expect($admit('instance.deploy', 99, ['complete_operation_id' => $complete->id])->status)->toBe(OperationStatus::Queued)
        ->and(fn () => $admit('instance.deploy', 100))->toThrow(fn (ResourceOperationException $error) => expect($error->details['operation_ids'])->toBe([$converge->id]));
});

it('marks the Node current once its role converge succeeds, even when Instance work moved the role digest on', function (): void {
    prepareReleaseTarget();
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);
    expect($fleet->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    $converge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    // The projection did not record the digest the pass now wants: the role's inputs changed while it ran.
    $this->node->forceFill(['release_inputs' => [...$this->node->fresh()->release_inputs, 'roles' => []]])->save();

    expect($fleet->execute($release))->toBeNull();
    $row = $release->refresh()->phases['fleet']['nodes'][$this->node->id];
    expect($row['status'])->toBe('current')
        ->and($row['roles']['app-prod'])->toBe('converged')
        ->and($release->phases['fleet']['status'])->toBe('current')
        ->and(Operation::query()->where('kind', 'node.converge')->count())->toBe(1);
});

it('keeps a current Node current for the rest of the pass while the next Node converges', function (): void {
    prepareReleaseTarget();
    $next = nextReleaseNode();
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);
    foreach ([$this->node, $next] as $member) {
        expect($fleet->execute($release))->toBe(10);
        $converge = Operation::query()->where('kind', 'node.converge')->latest('id')->firstOrFail();
        expect($converge->node_id)->toBe($member->id);
        $converge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
        // Task copies move the app-dev digest on with every copy; within the pass that is no reason to visit again.
        $member->forceFill(['release_inputs' => [...$member->fresh()->release_inputs, 'roles' => []]])->save();
    }

    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['status'])->toBe('current')
        ->and($release->phases['fleet']['nodes'][$this->node->id]['status'])->toBe('current')
        ->and($release->phases['fleet']['nodes'][$next->id]['status'])->toBe('current')
        ->and(Operation::query()->where('kind', 'node.converge')->count())->toBe(2);
});

it('counts a role converge run by hand during the pass, but never for a later pass or a recreated assignment', function (): void {
    prepareReleaseTarget();
    $release = releaseWithRow(['status' => 'pending']);
    $this->travel(1)->seconds();
    $hand = app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod']);
    $hand->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['status'])->toBe('current')
        ->and($release->phases['fleet']['nodes'][$this->node->id]['roles']['app-prod'])->toBe('converged')
        ->and(Operation::query()->where('kind', 'node.converge')->count())->toBe(1);

    // A day later the assignment is recreated while other work holds the Node: the next pass needs its own converge.
    $this->travel(1)->days();
    $this->node->roles()->where('role', RoleName::AppProd)->delete();
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $holder = app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id, [], 'test', null);
    expect($fleet->execute($release))->toBe(15);
    $holder->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    expect($fleet->execute($release))->toBe(10)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['status'])->toBe('running')
        ->and(Operation::query()->where('kind', 'node.converge')->count())->toBe(2);
});

it('marks a Node whose role converge failed, never retries it within the pass, and finishes the later Nodes', function (): void {
    prepareReleaseTarget();
    $next = nextReleaseNode();
    $failed = app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod'], release: true);
    $failed->update(['status' => OperationStatus::Failed, 'lock_key' => null, 'finished_at' => now(), 'error_code' => 'node.converge_failed']);
    $release = releaseWithRow(['status' => 'running', 'role_operation_id' => $failed->id]);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(10);
    $nextConverge = Operation::query()->where('kind', 'node.converge')->latest('id')->firstOrFail();
    expect($nextConverge->node_id)->toBe($next->id)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['status'])->toBe('failed')
        ->and($release->phases['fleet']['nodes'][$this->node->id]['error_code'])->toBe('node.converge_failed');
    $nextConverge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $this->travel(6)->minutes();

    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['nodes'][$next->id]['status'])->toBe('current')
        ->and($release->phases['fleet']['status'])->toBe('failed')
        ->and(Operation::query()->where('kind', 'node.converge')->where('node_id', $this->node->id)->count())->toBe(1);
});

/** Ends a converge as its projection does when it succeeds: a full converge records the host digest it captured. */
function succeedConverge(Operation $converge): void
{
    $converge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $host = $converge->input['_converge']['release_inputs']['host'] ?? null;
    if ($host !== null) {
        $node = Node::query()->findOrFail($converge->node_id);
        $node->forceFill(['release_inputs' => [...$node->release_inputs, 'host' => $host]])->save();
    }
}

it('converges each Node whose host inputs changed with the full Node converge, and leaves an unchanged Node alone', function (): void {
    prepareReleaseTarget();
    $next = nextReleaseNode();
    $third = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'third', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned']));
    $third->forceFill(['ssh_host_fingerprint' => 'SHA256:pinned', 'agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();
    $third->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $inputs = app(ReleaseNodeInputs::class);
    // The release changed the host inputs of the first and the third Node; their roles are applied.
    foreach ([$this->node, $next, $third] as $member) {
        $member->forceFill(['release_inputs' => ['host' => $member->is($next) ? $inputs->host(node: $next) : ReleaseNodeInputs::NodeScope.str_repeat('d', 64),
            'agent' => app(AgentDelivery::class)->pin($member), 'roles' => array_map(static fn (array $role): string => $role['digest'], $inputs->roles($member))]])->save();
    }
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    foreach ([$this->node, $third] as $member) {
        expect($fleet->execute($release))->toBe(10);
        $converge = Operation::query()->where('kind', 'node.converge')->latest('id')->firstOrFail();
        $capture = $converge->input['_converge'];
        // The converge an operator runs: the base and every assigned role, and it records the host digest on success.
        expect($converge->node_id)->toBe($member->id)
            ->and($capture['intent']['release_roles_only'] ?? false)->toBeFalse()
            ->and($capture['intent']['automatic'])->toBeTrue()
            ->and($capture['intent']['selected_roles'])->toBe(['app-prod'])
            ->and($capture['release_inputs']['host'])->toBe($inputs->host(node: $member))
            ->and($release->refresh()->phases['fleet']['nodes'][$member->id]['host'])->toBe('pending');
        succeedConverge($converge);
    }

    expect($fleet->execute($release))->toBeNull();
    $fleetPhase = $release->refresh()->phases['fleet'];
    expect($fleetPhase['status'])->toBe('current')
        ->and($fleetPhase['nodes'][$this->node->id])->toMatchArray(['status' => 'current', 'host' => 'converged'])
        ->and($fleetPhase['nodes'][$third->id])->toMatchArray(['status' => 'current', 'host' => 'converged'])
        ->and($fleetPhase['nodes'][$next->id])->toMatchArray(['status' => 'current', 'host' => 'unchanged'])
        ->and(json_encode($fleetPhase))->not->toContain('fleet.host_inputs_changed')
        ->and(Operation::query()->where('kind', 'node.converge')->where('node_id', $next->id)->exists())->toBeFalse()
        ->and($this->node->refresh()->release_inputs['host'])->toBe($inputs->host(node: $this->node));
});

it('marks a Node whose host converge failed and still converges the host of the later Nodes', function (): void {
    prepareReleaseTarget();
    $next = nextReleaseNode();
    foreach ([$this->node, $next] as $member) {
        $member->forceFill(['release_inputs' => [...$member->release_inputs, 'host' => ReleaseNodeInputs::NodeScope.str_repeat('d', 64)]])->save();
    }
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(10);
    Operation::query()->where('kind', 'node.converge')->sole()->update(['status' => OperationStatus::Failed, 'lock_key' => null,
        'finished_at' => now(), 'error_code' => 'node.converge_failed']);
    expect($fleet->execute($release))->toBe(10);
    $nextConverge = Operation::query()->where('kind', 'node.converge')->latest('id')->firstOrFail();
    expect($nextConverge->node_id)->toBe($next->id)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id])->toMatchArray(['status' => 'failed', 'host' => 'failed', 'error_code' => 'node.converge_failed']);
    succeedConverge($nextConverge);

    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['nodes'][$next->id]['status'])->toBe('current')
        ->and($release->phases['fleet']['status'])->toBe('failed')
        ->and(Operation::query()->where('kind', 'node.converge')->where('node_id', $this->node->id)->count())->toBe(1);
});

it('converges the serving Gateway Node\'s changed host inputs itself, last', function (): void {
    prepareReleaseTarget();
    $this->control->forceFill(['ssh_host_fingerprint' => 'SHA256:control', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned',
        'agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();
    app(GatewayServingHost::class)->remember($this->control);
    $inputs = app(ReleaseNodeInputs::class);
    $this->control->forceFill(['release_inputs' => ['host' => ReleaseNodeInputs::NodeScope.str_repeat('d', 64),
        'agent' => app(AgentDelivery::class)->pin($this->control), 'roles' => array_map(static fn (array $role): string => $role['digest'], $inputs->roles($this->control))]])->save();
    $this->node->forceFill(['release_inputs' => [...$this->node->release_inputs,
        'roles' => array_map(static fn (array $role): string => $role['digest'], $inputs->roles($this->node))]])->save();
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($converge->node_id)->toBe($this->control->id)
        ->and($converge->input['_converge']['intent']['selected_roles'])->toBe(['swamp', 'gateway'])
        ->and($converge->input['_converge']['release_inputs']['host'])->toBe($inputs->host(node: $this->control));
    succeedConverge($converge);

    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['status'])->toBe('current')
        ->and($release->phases['fleet']['nodes'][$this->control->id])->toMatchArray(['status' => 'current', 'host' => 'converged']);
});

it('admits a release role converge and a CI runner\'s full host converge without reading release inputs or the CLI catalog under the SQLite writer', function (bool $host): void {
    prepareReleaseTarget();
    $this->node->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    $release = releaseWithRow(['status' => 'pending']);
    $manifest = tmpfile();
    fwrite($manifest, json_encode(['number' => 9001, 'checksums_url' => 'https://example.test/SHA256SUMS',
        'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://example.test/orbit', 'sha256' => str_repeat('c', 64)]]], JSON_THROW_ON_ERROR));
    $static = new StaticCliRelease(stream_get_meta_data($manifest)['uri']);
    app()->instance(ReleaseHistory::class, $static);
    $lookups = new ArrayObject;
    app()->instance(CliReleaseCatalog::class, new readonly class($static, $lookups) implements CliReleaseCatalog
    {
        /** @param ArrayObject<int, int> $lookups */
        public function __construct(private CliReleaseCatalog $catalog, private ArrayObject $lookups) {}

        public function find(string $commit, CliReleaseName $release): DesiredCliReleaseData
        {
            $this->lookups->append(DB::transactionLevel());

            return $this->catalog->find($commit, $release);
        }
    });
    $cli = app(DesiredFleetState::class)->current()->cli;
    $inputs = app(ReleaseNodeInputs::class);
    $this->node->forceFill(['release_inputs' => [...$this->node->release_inputs, 'cli' => $cli->version,
        'host' => $host ? ReleaseNodeInputs::NodeScope.str_repeat('d', 64) : $inputs->host(node: $this->node),
        'roles' => $host ? array_map(static fn (array $role): string => $role['digest'], $inputs->roles($this->node)) : []]])->save();
    // The cached catalog answer is gone once the pass has read its inputs, so a lookup under the writer would reach the catalog.
    $reads = new ArrayObject;
    app()->instance(ReleaseNodeInputs::class, new ObservedReleaseInputs($reads, static fn () => Cache::flush()));
    $lookups->exchangeArray([]);
    // The test database wraps each test in a transaction of its own.
    $outside = DB::transactionLevel();

    expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBe(10);

    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($converge->input['_converge']['intent']['release_roles_only'] ?? false)->toBe(! $host)
        ->and(array_keys($converge->input['_converge']['release_inputs']['roles']))->toBe($host ? $this->node->roles()->where('role', '!=', RoleName::Gateway)->orderBy('id')->pluck('id')->all()
            : $this->node->roles()->whereIn('role', [RoleName::AppProd, RoleName::CiRunner])->orderBy('id')->pluck('id')->all())
        ->and($converge->input['_converge']['release_inputs']['host'])->toBe($host ? $inputs->host(node: $this->node) : null)
        ->and(array_unique(array_column($reads->getArrayCopy(), 1)))->toBe([$outside])
        ->and(array_column($reads->getArrayCopy(), 0))->toContain('host', 'roles')
        ->and(array_filter($lookups->getArrayCopy(), static fn (int $level): bool => $level !== $outside))->toBe([]);
    if ($host) {
        expect($converge->input['_converge']['intent']['cli']['release']['version'] ?? null)->toBe($cli->version);
    }
})->with(['a role-only release converge' => false, 'a full host converge' => true]);

it('admits a host converge by the automatic converge rules: task work never blocks it, Instance work queues it', function (): void {
    prepareReleaseTarget();
    $this->node->forceFill(['release_inputs' => [...$this->node->release_inputs, 'host' => ReleaseNodeInputs::NodeScope.str_repeat('d', 64)]])->save();
    $holder = app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id, [], 'test', null);
    app(OperationStore::class)->create('tasks.copy_turn', 'task_group', 77, $this->node->id, [], 'test', null);
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(15)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['waiting_for'])->toBe([$holder->id])
        ->and(fn () => app(OperationStore::class)->create('instance.deploy', 'instance', 100, $this->node->id, [], 'test', null))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->getMessage())->toBe('An automatic role converge waits for this Node.'));
    $holder->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    expect($fleet->execute($release))->toBe(10)
        ->and(Operation::query()->where('kind', 'node.converge')->sole()->input['_converge']['intent']['automatic'])->toBeTrue();
});

it('never refuses or holds power work for an automatic role converge, waiting or running', function (string $kind): void {
    prepareReleaseTarget();
    $admit = fn (string $kind, int $instance): Operation => app(OperationStore::class)->create($kind, 'instance', $instance, $this->node->id, [], 'test', null);
    $holder = $admit('instance.deploy', 99);
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    // The converge waits for the deploy; a visitor's wake or an idle sleep goes on beside that wait.
    expect($fleet->execute($release))->toBe(15)
        ->and($admit($kind, 100)->status)->toBe(OperationStatus::Queued);
    $holder->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    // The open power Operation does not hold the converge back, and a power Operation goes on beside the running converge.
    expect($fleet->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($converge->state['admitted_beside'] ?? [])->toBe([])
        ->and($admit($kind, 101)->status)->toBe(OperationStatus::Queued)
        ->and(fn () => $admit('instance.deploy', 102))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
})->with(['instance.wake', 'instance.sleep', 'instance.start']);

it('never keeps a release row waiting for a deploy the release admitted once it ended, and drops it from waiting_for', function (string $end): void {
    prepareReleaseTarget();
    // As Operation 31729: the release's own refresh admitted the deploy (its input's `origin`).
    $deploy = app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id, ['origin' => ContainerDeployOutcome::ReleaseOrigin],
        NestedFleetRefresh::Trigger, null, lockKey: 'instance:99');
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(15)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['waiting_for'])->toBe([$deploy->id]);
    $deploy->update(match ($end) {
        // The fence keeps the Instance lock of a signed order without its receipt (ADR 0266, R2).
        'interrupted under the fence' => ['status' => OperationStatus::Interrupted, 'error_code' => 'executor.result_unknown', 'finished_at' => now(),
            'state' => [ExecutorFence::Issued => true]],
        // Expired before it had a Swamp run, its cancellation still forwarding.
        'timed out before its run' => ['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'finished_at' => now(), 'lock_key' => null,
            'state' => ['native_cancel_requested_at' => now()->toISOString()]],
        // Expired during its run, its cancellation still forwarding.
        'timed out awaiting its cancellation' => ['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'finished_at' => now(), 'lock_key' => null,
            'state' => ['swamp_run_id' => 'run-1', 'native_timeout' => [], 'native_cancel_requested_at' => now()->toISOString()]],
        'succeeded' => ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null],
        'failed' => ['status' => OperationStatus::Failed, 'error_code' => 'deployment.branch_diverged', 'finished_at' => now(), 'lock_key' => null],
        'cancelled' => ['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'lock_key' => null],
    });

    expect($fleet->execute($release))->toBe(10);
    expect($release->refresh()->phases['fleet']['nodes'][$this->node->id])->not->toHaveKey('waiting_for');
    $converge = Operation::query()->where('kind', 'node.converge')->sole();

    // A wait that ended without this pass's converge, such as one an operator ran by hand, leaves no stale ID behind either.
    $phases = $release->phases;
    $phases['fleet']['nodes'][$this->node->id]['waiting_for'] = [$deploy->id];
    $release->update(['phases' => $phases]);
    $converge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    $this->node->forceFill(['release_inputs' => [...$this->node->fresh()->release_inputs, 'roles' => []]])->save();
    expect($fleet->execute($release))->toBeNull();
    expect($release->refresh()->phases['fleet']['nodes'][$this->node->id])->toMatchArray(['status' => 'current'])->not->toHaveKey('waiting_for');
})->with(['interrupted under the fence', 'timed out before its run', 'timed out awaiting its cancellation', 'succeeded', 'failed', 'cancelled']);

it('still holds the Node for an ended deploy the release did not admit while its result is unknown', function (array $deploy): void {
    prepareReleaseTarget();
    // A deploy by hand, even from a caller named `release`: its admission recorded no release origin.
    $held = app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id, [], NestedFleetRefresh::Trigger, null, lockKey: 'instance:99');
    $held->update(['status' => OperationStatus::Interrupted, 'finished_at' => now(), ...$deploy]);
    $release = releaseWithRow(['status' => 'pending']);

    expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBe(15)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['waiting_for'])->toBe([$held->id]);
})->with([
    'fenced' => [['error_code' => 'executor.result_unknown', 'state' => [ExecutorFence::Issued => true]]],
    'cancellation unconfirmed' => [['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'lock_key' => null,
        'state' => ['swamp_run_id' => 'run-1', 'native_timeout' => [], 'native_cancel_requested_at' => now()->toISOString()]]],
]);

it('drops a deploy that a release linked before the input named its origin from the Node wait, and keeps its Instance fence', function (): void {
    prepareReleaseTarget();
    // Admitted before the upgrade: no `origin`, only an old release's `default_refresh` row names it. It ended under the fence.
    $deploy = app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id, [], NestedFleetRefresh::Trigger, null, lockKey: 'instance:99');
    $deploy->update(['status' => OperationStatus::Interrupted, 'error_code' => 'executor.result_unknown', 'finished_at' => now(), 'state' => [ExecutorFence::Issued => true]]);
    GatewayRelease::query()->create(['sha' => str_repeat('b', 40), 'trigger' => 'auto', 'outcome' => 'verified', 'duration_ms' => 0,
        'phases' => ['default_refresh' => ['status' => 'pending', 'defaults' => [99 => ['status' => 'pending', 'operation_id' => $deploy->id]]]]]);
    // Many releases followed it: the owner it names never expires.
    foreach (range(1, 25) as $newer) {
        GatewayRelease::query()->create(['sha' => str_pad((string) $newer, 40, 'c'), 'trigger' => 'auto', 'outcome' => 'verified', 'duration_ms' => 0, 'phases' => []]);
    }
    (require database_path('migrations/2027_03_23_000000_mark_release_linked_deploys.php'))->up();
    $release = releaseWithRow(['status' => 'pending']);

    // Its dispatch keeps a newer checkout, by the same owner that admission reads.
    expect(ContainerDeployOutcome::releaseAdmitted($deploy->refresh()))->toBeTrue()
        ->and(ContainerDeployOutcome::keepsNewer($deploy))->toBeTrue();
    expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBe(10)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id])->not->toHaveKey('waiting_for')
        ->and($deploy->refresh()->lock_key)->toBe('instance:99')
        ->and(fn () => app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id, [], 'test', null, lockKey: 'instance:99'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
});
