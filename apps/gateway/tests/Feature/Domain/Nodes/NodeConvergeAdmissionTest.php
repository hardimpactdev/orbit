<?php

declare(strict_types=1);

use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Actions\Nodes\AdmitNodeEnrollmentOperationAction;
use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Nodes\StartNodeConvergeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\FleetReleaseLag;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Fleet\ReleaseNodeInputs;
use App\Domain\Instances\NestedFleetLayout;
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
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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

it('refuses a base converge beside a converge with roles of another Node, in both orders, and the hub waits for it', function (bool $baseFirst): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $other = otherConvergeNode($this->node, $this->control);
    $other->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $roles = '/api/v1/nodes/'.$other->id.'/converge';
    $this->postJson($baseFirst ? $this->url : $roles, $baseFirst ? ['base' => true] : ['roles' => ['app-prod']])->assertAccepted();

    $this->postJson($baseFirst ? $roles : $this->url, $baseFirst ? ['roles' => ['app-prod']] : ['base' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(Operation::query()->count())->toBe(1);
    if (! $baseFirst) {
        // A converge with roles holds the hub as before: a base converge of the hub itself waits too.
        expect(fn () => app(NodeNetworkReservation::class)->hold($this->control, true, fn () => null))->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
    }
})->with(['base first' => true, 'roles first' => false]);

it('treats an open converge without the base mark as one with roles', function (): void {
    $this->control->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $other = otherConvergeNode($this->node, $this->control);
    $this->postJson('/api/v1/nodes/'.$other->id.'/converge', ['base' => true])->assertAccepted();
    $open = Operation::query()->sole();
    $open->forceFill(['input' => [...$open->input, '_converge' => [...$open->input['_converge'], 'network' => ['hub_id' => $this->control->id]]]])->save();

    $this->postJson($this->url, ['base' => true])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
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

it('allocates every app-dev Node\'s Instance bridge in the admission, before the read-only capture', function (array $scope, ?string $bridge): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
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
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode($recorded)]);

    $this->postJson($this->url, ['base' => true, 'full' => $full])->assertAccepted();
    $first = Operation::query()->sole();
    $intent = $first->input['_converge']['intent'];
    expect($intent['recorded_digests'])->toBe($full ? [] : $recorded)
        ->and($this->node->refresh()->converge_digests)->toBeNull();

    $first->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $this->postJson($this->url, ['base' => true])->assertAccepted();
    $second = Operation::query()->whereKeyNot($first->id)->sole();
    expect($second->input['_converge']['intent']['recorded_digests'])->toBe([]);
})->with(['recorded' => false, 'full' => true]);

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
    $this->node->forceFill(['converge_digests' => ['roles' => str_repeat('1', 64)]])->save();
    $role = $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);

    $operation = app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod'], release: true);

    $capture = $operation->input['_converge'];
    expect($operation->lock_key)->toBe('node:'.$this->node->id)
        ->and($capture['intent']['release_roles_only'])->toBeTrue()
        ->and($capture['intent']['recorded_digests'])->toBe([])
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
    expect(fn () => $admit($participants))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect($admit([$this->control->id])->status)->toBe(OperationStatus::Queued)
        ->and(Operation::query()->count())->toBe(3);
    $scope->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    expect($admit($participants)->status)->toBe(OperationStatus::Queued);
})->with(['instance.deploy', 'tasks.copy_turn']);

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

/** A verified release whose fleet pass holds one row for the target Node. */
function releaseWithRow(array $row): GatewayRelease
{
    $commit = str_repeat('a', 40);
    config(['app.version' => $commit, 'orbit.gateway_checkout' => base_path()]);

    return GatewayRelease::query()->create(['sha' => $commit, 'trigger' => 'auto', 'outcome' => 'verified', 'duration_ms' => 0,
        'phases' => ['fleet' => ['status' => 'pending', 'nodes' => [test()->node->id => ['node_id' => test()->node->id,
            'name' => test()->node->name, ...$row]]]]]);
}

it('queues a busy-refused release role converge ahead of new Instance work and admits it once the holder ends', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($this->node)]])->save();
    $admit = fn (): Operation => app(OperationStore::class)->create('instance.deploy', 'instance', 99, $this->node->id,
        ['participant_node_ids' => [$this->node->id]], 'test', null);
    $holder = $admit();
    $release = releaseWithRow(['status' => 'pending']);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect($fleet->execute($release))->toBe(15)
        ->and($release->refresh()->phases['fleet']['nodes'][$this->node->id]['status'])->toBe('pending');
    // The waiting converge refuses new work on the Node, so the holder that ends is the last one before it.
    expect($admit)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    $holder->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    expect($fleet->execute($release))->toBe(10);
    $converge = Operation::query()->where('kind', 'node.converge')->sole();
    expect($release->refresh()->phases['fleet']['nodes'][$this->node->id]['role_operation_id'])->toBe($converge->id);
    $converge->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);
    // Once admitted, the converge no longer holds new Instance work back.
    expect($admit()->status)->toBe(OperationStatus::Queued);
});

it('marks the Node current once its role converge succeeds, even when Instance work moved the role digest on', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($this->node)]])->save();
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

it('counts a role converge run by hand after the release as the pass\'s own', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($this->node)]])->save();
    $release = releaseWithRow(['status' => 'pending']);
    $this->travel(1)->seconds();
    $hand = app(StartNodeConvergeAction::class)->execute($this->node, roles: ['app-prod']);
    $hand->update(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()]);

    expect(app(ContinueReleaseFleetAction::class)->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['status'])->toBe('current')
        ->and($release->phases['fleet']['nodes'][$this->node->id]['roles']['app-prod'])->toBe('converged')
        ->and(Operation::query()->where('kind', 'node.converge')->count())->toBe(1);
});

it('marks a Node whose role converge failed and goes on to the next Node', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $this->node->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($this->node)]])->save();
    $next = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'next', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '10.44.0.8', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned']));
    $next->forceFill(['ssh_host_fingerprint' => 'SHA256:pinned', 'agent_secret_hash' => hash('sha256', 'private-test-agent-secret')])->save();
    $next->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $next->forceFill(['release_inputs' => ['host' => app(ReleaseNodeInputs::class)->host(), 'agent' => app(AgentDelivery::class)->pin($next)]])->save();
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

    expect($fleet->execute($release))->toBeNull()
        ->and($release->refresh()->phases['fleet']['nodes'][$next->id]['status'])->toBe('current')
        ->and($release->phases['fleet']['status'])->toBe('failed')
        ->and(Operation::query()->where('kind', 'node.converge')->count())->toBe(2);
});
