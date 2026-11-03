<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeEnrollmentOperationAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NativeNodeEnrollmentProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/orbit-native-enroll-'.bin2hex(random_bytes(5));
    mkdir($this->home.'/wireguard', 0700, true);
    file_put_contents($this->home.'/bootstrap.key', 'fixture-bootstrap-private-value');
    file_put_contents($this->home.'/wireguard/public.key', base64_encode(str_repeat('w', 32)));
    file_put_contents($this->home.'/delivery.json', json_encode(['commit' => str_repeat('a', 40), 'version' => '0.9.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64), 'url' => 'https://example.invalid/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->home.'/delivery.json', $this->home.'/cache'));
    FleetFixtures::bind();
    config(['orbit.home' => $this->home, 'swamp.run_token' => 'fixture.token']);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn($this->home.'/bootstrap.key');
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-public-key');
    app()->instance(SshKeyProvider::class, $keys);
    $ca = Mockery::mock(LeafCertificateSigner::class);
    $ca->shouldReceive('rootCertificate')->andReturn('fixture public root certificate');
    app()->instance(LeafCertificateSigner::class, $ca);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'],
            'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started',
            'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => '4b700960-be72-4d01-b5ab-8e897a40b138']]];
    app()->instance(WebSocketClient::class, $this->socket);
    $control = Node::query()->forceCreate(['id' => 209, 'name' => 'control', 'platform' => 'linux',
        'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.209',
        'wireguard_ip' => '10.44.0.209', 'status' => 'active',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'control-public-key']);
    foreach ([RoleName::Gateway, RoleName::Swamp] as $role) {
        $control->roles()->create(['role' => $role, 'status' => 'active']);
    }
    $this->key = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat("\1", 32));
    $this->fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($this->key), true)), '=');
    DB::beginTransaction();
});
afterEach(function (): void {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    (new Filesystem)->deleteDirectory($this->home);
});

function nativeMacEnrollment(object $test): Operation
{
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'native-mac', publicSshHost: '192.0.2.42', user: 'mac', orbitUser: 'mac', wireguardIp: '10.44.0.42',
        expectedSshHostFingerprint: $test->fingerprint, platform: 'macos', platformProvided: true));
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => '4b700960-be72-4d01-b5ab-8e897a40b138']])->save();

    return $operation;
}

it('admits one Mac run with vault names and leaves its observed identity unset until the native result', function (): void {
    $operation = nativeMacEnrollment($this);
    $input = app(DirectOperation::class)->workflowInputs($operation);
    $node = Node::query()->findOrFail($operation->target_id);
    expect($operation->kind)->toBe('node.add')
        ->and($input['intent']['platform'])->toBe('macos')
        ->and($input['intent']['agent']['files'])->toBe([])
        ->and($input['intent']['roles'])->toBe([])
        ->and($input['execution_secrets']['bootstrap_identity']['key'])->toBe('bootstrap-identity-node-'.$node->id)
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain('fixture-bootstrap-private-value')
        ->and($node->status->value)->toBe('provisioning')
        ->and($node->ssh_host_key)->toBeNull();
});

it('refuses an unproved successful terminal event without activating the candidate', function (): void {
    $operation = nativeMacEnrollment($this);
    expect(fn () => app(NativeNodeEnrollmentProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'started_at' => '2026-11-02T20:00:00Z', 'finished_at' => '2026-11-02T20:00:01Z', 'status' => 'succeeded',
        'result' => ['steps' => []]]))->toThrow(ResourceOperationException::class);
    expect(Node::query()->findOrFail($operation->target_id)->status->value)->toBe('provisioning');
});

it('records a proved Mac identity without installing an observer or changing its tunnel key', function (): void {
    $operation = nativeMacEnrollment($this);
    $observed = ['probed' => true, 'architecture' => 'arm64', 'host_type' => 'ssh-ed25519',
        'public_key' => $this->key, 'host_key_fingerprint' => $this->fingerprint];
    $projected = app(NativeNodeEnrollmentProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'started_at' => '2026-11-02T20:00:00Z', 'finished_at' => '2026-11-02T20:00:01Z', 'status' => 'succeeded',
        'result' => ['steps' => ['Check the Node' => ['status' => 'succeeded'],
            'Bind the Node identity' => ['status' => 'succeeded', 'stdout' => json_encode($observed, JSON_THROW_ON_ERROR)]]]]);
    $node = Node::query()->findOrFail($operation->target_id);
    expect($projected->result['enrolled'])->toBeTrue()
        ->and($node->status->value)->toBe('active')
        ->and($node->architecture)->toBe('arm64')
        ->and($node->ssh_host_key)->toBe($this->key)
        ->and($node->agent_secret_hash)->toBeNull()
        ->and($node->wireguard_public_key)->toBeNull();
});

it('records the native failure code without inventing observations', function (string $step, string $errorCode): void {
    $operation = nativeMacEnrollment($this);
    app(NativeNodeEnrollmentProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'started_at' => '2026-11-02T20:00:00Z', 'finished_at' => '2026-11-02T20:00:01Z', 'status' => 'failed', 'error_code' => 'operation.native_failed',
        'result' => ['steps' => [$step => ['status' => 'failed']], 'failed_step' => $step]]);
    $node = Node::query()->findOrFail($operation->target_id);
    expect($node->status->value)->toBe('failed')
        ->and($node->ssh_host_key)->toBeNull()
        ->and($node->architecture)->toBeNull()
        ->and($operation->fresh()->error_code)->toBe($errorCode);
})->with([
    ['Require approved host key', 'node.ssh_host_key_mismatch'],
    ['Read the bootstrap account-0', 'node.bootstrap_account_unavailable'],
    ['Verify managed SSH-0', 'node.orbit_ssh_failed'],
]);

it('freezes a roleless Linux baseline without committing its host-key placeholders', function (): void {
    FleetFixtures::node('vpn', [RoleName::Vpn]);
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'native-linux', publicSshHost: '192.0.2.43', wireguardIp: '10.44.0.43', expectedSshHostFingerprint: $this->fingerprint));
    $node = Node::query()->findOrFail($operation->target_id);
    $input = app(DirectOperation::class)->workflowInputs($operation);
    expect($input['intent']['packages'])->toContain('wireguard', 'sudo')
        ->and($input['intent']['roles'])->toBe([])
        ->and($input['intent']['network']['hub_files'])->toHaveCount(3)
        ->and($input['intent']['agent']['sha256'])->toBe(str_repeat('b', 64))
        ->and($input['execution_secrets']['agent_secret']['key'])->toBe('agent-secret-node-'.$node->id)
        ->and($node->ssh_host_key)->toBeNull()
        ->and($node->wireguard_public_key)->toBeNull()
        ->and($node->status->value)->toBe('provisioning')
        ->and(json_encode($input['intent'], JSON_THROW_ON_ERROR))->not->toContain('PrivateKey =', 'fixture-bootstrap-private-value');
});

it('captures requested roles and the observer scope without committing an observed identity', function (): void {
    FleetFixtures::node('vpn', [RoleName::Vpn]);
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'new-role', publicSshHost: '192.0.2.70', user: 'root', orbitUser: 'managed', wireguardIp: '10.44.0.70',
        expectedSshHostFingerprint: $this->fingerprint, roles: [RoleName::AppDev], tld: 'enrollment.test'));
    $node = Node::query()->findOrFail($operation->node_id);
    expect($operation->input['intent']['bootstrap']['user'])->toBe('root')
        ->and($operation->input['intent']['managed_user'])->toBe('managed')
        ->and($operation->input['intent']['roles'][0]['kind'])->toBe('node.role_add')
        ->and($operation->input['intent']['roles'][0]['intent']['role'])->toBe('app-dev')
        ->and($operation->input['intent']['agent']['unit'])->toContain('BindReadOnlyPaths=-/home/managed/apps')
        ->and($node->roles()->sole()->status->value)->toBe('provisioning')
        ->and($node->architecture)->toBeNull()
        ->and($node->ssh_host_key)->toBeNull();
});

it('captures a first VPN hub with forwarding, its pinned listener PHP and a full DNS publication', function (): void {
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'new-vpn', publicSshHost: '192.0.2.74', wireguardIp: '10.44.0.74',
        expectedSshHostFingerprint: $this->fingerprint, roles: [RoleName::Vpn]));
    $intent = $operation->input['intent'];
    $node = Node::query()->findOrFail($operation->node_id);
    expect($intent['network']['is_hub'])->toBeTrue()
        ->and($intent['private_dns'])->toHaveCount(1)
        ->and($intent['private_dns'][0]['dns']['service'])->toContain('/usr/bin/php8.5')
        ->and($intent['private_dns'][0]['backend'])->toContain('listen-address=127.0.0.55')
        ->and($intent['roles'][0]['intent']['packages'])->toContain('dnsmasq')
        ->and($intent['roles'][0]['intent']['php_packages'])->not->toBeEmpty()
        ->and($node->wireguard_public_key)->toBeNull();
});

it('preserves existing identity on admission and on a failed re-enrollment', function (): void {
    FleetFixtures::node('vpn', [RoleName::Vpn]);
    $node = FleetFixtures::node('existing-enrollment', [RoleName::Database]);
    $node->update(['ssh_host_fingerprint' => $this->fingerprint, 'lan_ip' => '192.0.2.80']);
    $prior = $node->refresh()->attributesToArray();
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: $node->name, publicSshHost: '192.0.2.81', architecture: 'arm64'));
    expect($node->fresh()->attributesToArray())->toBe($prior)
        ->and($operation->input['intent']['expected_architecture'])->toBe('x86_64')
        ->and($operation->input['intent']['roles'][0]['kind'])->toBe('node.role_converge')
        ->and($node->roles()->sole()->status->value)->toBe('active');
    $operation->update(['status' => 'running', 'state' => ['swamp_run_id' => '4b700960-be72-4d01-b5ab-8e897a40b138']]);
    app(NativeNodeEnrollmentProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'],
        'started_at' => '2026-11-02T20:00:00Z', 'finished_at' => '2026-11-02T20:00:01Z', 'status' => 'failed',
        'error_code' => 'node.bootstrap_failed', 'result' => ['steps' => [], 'failed_step' => 'Read the platform']]);
    expect($node->fresh()->attributesToArray())->toBe($prior)
        ->and($node->roles()->sole()->status->value)->toBe('active');
});

it('refuses invalid enrollment before persisting a Node or Operation', function (string $case): void {
    $data = new ProvisionNodeData(name: 'refused-enrollment', publicSshHost: '192.0.2.90', expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43));
    match ($case) {
        'missing fingerprint' => $data->expectedSshHostFingerprint = null,
        'account' => $data->orbitUser = '-invalid',
        'endpoint' => $data->wireguardEndpointOverride = 'shell invalid',
        'missing tld' => $data->roles = [RoleName::AppDev],
        'reserved tld' => $data->tld = 'orbit',
    };
    expect(fn () => app(AdmitNodeEnrollmentOperationAction::class)->execute($data))->toThrow(ResourceOperationException::class);
    expect(Node::query()->where('name', $data->name)->exists())->toBeFalse();
    expect(Operation::query()->exists())->toBeFalse();
})->with(['missing fingerprint', 'account', 'endpoint', 'missing tld', 'reserved tld']);

it('refuses Swamp enrollment of an executor Node without changing its identity', function (): void {
    $node = FleetFixtures::node('executor-enrollment');
    config(['dependency_detection.services_node_id' => $node->id]);
    expect(fn () => app(ProvisionNodeAction::class)->start(new ProvisionNodeData(name: $node->name, publicSshHost: '', expectedSshHostFingerprint: $node->ssh_host_fingerprint)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.executor_dependency'));
    expect(Operation::query()->count())->toBe(0);
    expect($node->refresh()->status->value)->toBe('active');
});

it('canonicalizes requested and retained architecture names at enrollment admission', function (string $platform, string $alias, string $canonical): void {
    $mac = $platform === 'macos';
    if (! $mac) {
        FleetFixtures::node('vpn', [RoleName::Vpn]);
    }
    $operation = app(ProvisionNodeAction::class)->start(new ProvisionNodeData(name: 'alias-enrollment', publicSshHost: '192.0.2.99', platform: $platform,
        architecture: $alias, user: $mac ? 'mini' : 'root', orbitUser: $mac ? 'mini' : 'orbit', wireguardIp: '10.44.0.99', expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43)));
    expect($operation->input['intent']['expected_architecture'])->toBe($canonical);
})->with([['linux', 'amd64', 'x86_64'], ['macos', 'aarch64', 'arm64']]);

it('refuses enrollment of a Node with Instances before changing its serving scope', function (): void {
    $node = FleetFixtures::node('tld-enrollment', [RoleName::AppDev]);
    $node->update(['tld' => 'before.test']);
    $project = Project::query()->create(['name' => 'Enrollment TLD', 'slug' => 'enrollment-tld', 'repository_url' => 'https://example.test/tld.git']);
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default', 'checkout_path' => '/srv/tld', 'status' => 'active']);
    $route = Route::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'generation_basis_node_id' => $node->id,
        'domain' => 'enrollment-tld.before.test', 'provenance' => 'generated', 'publication' => 'private']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    expect(fn () => app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: $node->name, publicSshHost: '', tld: 'after.test')))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.has_instances'));
    expect($node->fresh()->tld)->toBe('before.test')
        ->and($node->fresh()->status->value)->toBe('active')
        ->and($route->fresh()->domain)->toBe('enrollment-tld.before.test');
});
