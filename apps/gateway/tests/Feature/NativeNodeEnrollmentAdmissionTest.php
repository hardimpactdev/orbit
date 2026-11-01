<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeEnrollmentOperationAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\NativeEnrollmentFixtures;

beforeEach(function (): void {
    $fixture = NativeEnrollmentFixtures::bind();
    $this->gateway = $this->markAsGateway($fixture['gateway']);
    $this->hub = $fixture['hub'];
    $this->socket = $fixture['socket'];
});

it('refuses an unavailable enrollment kind before recording reservations or execution copies', function (): void {
    app()->instance(DirectOperation::class, new DirectOperation([]));
    expect(fn () => app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'unavailable-enrollment', publicSshHost: '192.0.2.240', wireguardIp: '10.44.0.240',
        roles: [RoleName::Metrics], expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43),
    )))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
    expect($this->socket->sent)->toBe([])
        ->and(Node::query()->where('name', 'unavailable-enrollment')->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse();
});

it('reserves one real new Node and submits public native intent without agent or SSH effects', function (): void {
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'new-native-enrollment', publicSshHost: '192.0.2.240', wireguardIp: '10.44.0.240',
        expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43),
    ));
    $node = Node::query()->where('name', 'new-native-enrollment')->sole();
    $intent = $operation->input['intent'];
    expect($operation->target_id)->toBe($node->id)
        ->and($operation->node_id)->toBe($node->id)
        ->and($node->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->ssh_host_key)->toBeNull()
        ->and($node->agent_secret_hash)->toBeNull()
        ->and($intent['candidate']['node_id'])->toBe($node->id)
        ->and($intent['bootstrap']['target']['host'])->toBe('192.0.2.240')
        ->and($intent['ssh_identity']['control_node_id'])->toBe(209)
        ->and($intent['roles'])->toBe([])
        ->and($intent['settings']['storage'])->toBeNull()
        ->and($intent['footprint'])->not->toHaveKey('secret_hash')
        ->and($intent['tools']['node_id'])->toBe($node->id)
        ->and($node->roles()->exists())->toBeFalse();
    $runs = array_values(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'));
    expect($runs)->toHaveCount(1)
        ->and($runs[0]['payload']['workflowIdOrName'])->toBe('operation-node-add-native')
        ->and($runs[0]['payload']['inputs']['intent'])->toBe($intent)
        ->and(json_encode($operation->input, JSON_THROW_ON_ERROR))->not->toContain('worker_credentials', 'worker_token', 'identityContent', 'PRIVATE KEY');
});

it('preserves actual existing identity and settings while capturing their candidate', function (): void {
    $node = FleetFixtures::node('existing-native-enrollment');
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43), 'wireguard_public_key' => base64_encode(str_repeat('n', 32)),
        'settings' => ['t3' => ['connection' => 'retained']]]);
    $before = $node->fresh()->getAttributes();
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: $node->name, publicSshHost: '192.0.2.240', wireguardIp: '10.44.0.240', orbitUser: 'candidate',
    ));
    expect($node->refresh()->getAttributes())->toBe($before)
        ->and($operation->input['enrollment']['existed'])->toBeTrue()
        ->and($operation->input['intent']['candidate']['user'])->toBe('candidate')
        ->and($operation->input['intent']['candidate']['wireguard_ip'])->toBe('10.44.0.240')
        ->and($operation->input['intent']['hub']['before']['address'])->toBe($node->wireguard_ip)
        ->and($operation->input['intent']['hub']['desired']['address'])->toBe('10.44.0.240');
});

it('rolls back new reservations and creates no Operation when execution-copy admission fails', function (): void {
    $this->socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'forbidden']]];
    expect(fn () => app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'refused-native-enrollment', publicSshHost: '192.0.2.240', wireguardIp: '10.44.0.240',
        expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43),
    )))->toThrow(ResourceOperationException::class);
    expect(Node::query()->where('name', 'refused-native-enrollment')->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse()
        ->and(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'))->toBe([]);
});

it('returns one admitted Operation from the normal API and replays its idempotency key', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $payload = ['name' => 'api-native-enrollment', 'public_ssh_host' => '192.0.2.241',
        'wireguard_ip' => '10.44.0.241', 'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)];
    $first = $this->withHeader('Idempotency-Key', 'enrollment-api-proof')->postJson('/api/v1/nodes', $payload)
        ->assertAccepted()->assertJsonPath('data.kind', 'node.add')->assertJsonPath('data.status', 'running');
    $id = $first->json('data.id');
    $node = Node::query()->where('name', 'api-native-enrollment')->sole();
    expect($node->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->ssh_host_key)->toBeNull();
    $this->withHeader('Idempotency-Key', 'enrollment-api-proof')->postJson('/api/v1/nodes', $payload)
        ->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withHeader('Idempotency-Key', 'enrollment-api-proof')->postJson('/api/v1/nodes',
        [...$payload, 'public_ssh_host' => '192.0.2.242'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    expect(Operation::query()->where('kind', 'node.add')->count())->toBe(1)
        ->and(array_filter($this->socket->sent, static fn (array $frame): bool => $frame['type'] === 'workflow.run'))->toHaveCount(1);
});

it('refuses an untrusted API caller before reserving a target or issuing a native run', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.250'])->postJson('/api/v1/nodes', [
        'name' => 'untrusted-native-enrollment', 'public_ssh_host' => '192.0.2.241',
        'wireguard_ip' => '10.44.0.241', 'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43),
    ])->assertForbidden();
    expect(Node::query()->where('name', 'untrusted-native-enrollment')->exists())->toBeFalse()
        ->and(Operation::query()->where('kind', 'node.add')->exists())->toBeFalse()
        ->and($this->socket->sent)->toBe([]);
});

it('captures a populated app-dev TLD change without bootstrap or a premature identity write', function (): void {
    $node = FleetFixtures::node('native-tld-target', [RoleName::AppDev]);
    $node->update(['tld' => 'prior.test', 'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $project = Project::query()->create(['name' => 'Enrollment TLD', 'slug' => 'enrollment-tld',
        'repository_url' => 'https://example.test/enrollment-tld.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'development', 'checkout_path' => '/srv/enrollment-tld', 'status' => 'active',
        'provisioning_step' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'generation_basis_node_id' => $node->id, 'domain' => 'development.enrollment-tld.prior.test',
        'publication' => 'private', 'provenance' => 'generated', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $before = $node->fresh()->getAttributes();

    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: $node->name, publicSshHost: '', tld: 'next.test', tldProvided: true,
    ));
    $intent = $operation->input['intent'];
    $publication = collect($intent['route_mutations']['forward']['publications'])->firstWhere('node_id', $node->id);

    expect($intent['mode'])->toBe('tld_only')
        ->and($intent['tools'])->toBeNull()
        ->and($intent['network'])->toBeNull()
        ->and($intent['hub'])->toBeNull()
        ->and($intent['roles'])->toBe([])
        ->and($publication['before']['caddy']['content'])->toContain('development.enrollment-tld.prior.test')
        ->and($publication['after']['caddy']['content'])->toContain('development.enrollment-tld.next.test')
        ->and($node->refresh()->getAttributes())->toBe($before)
        ->and($node->roles()->sole()->status)->toBe(LifecycleStatus::Active)
        ->and($route->refresh()->domain)->toBe('development.enrollment-tld.prior.test');
});

it('admits a provisional role with parent tools and pending target pins without publishing identity', function (RoleName $role): void {
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(
        name: 'new-role-enrollment', publicSshHost: '192.0.2.240', wireguardIp: '10.44.0.240',
        roles: [$role], tld: 'native-role.test', tldProvided: true, expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43),
    ));
    $node = Node::query()->where('name', 'new-role-enrollment')->sole();
    $assignment = $node->roles()->sole();
    $intent = $operation->input['intent'];
    $captured = $intent['roles'][0];

    expect($node->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->ssh_host_key)->toBeNull()
        ->and($assignment->status)->toBe(LifecycleStatus::Provisioning)
        ->and($captured['assignment_id'])->toBe($assignment->id)
        ->and($captured['role'])->toBe($role->value)
        ->and($captured['prepared_tools_required'])->toBeTrue()
        ->and($captured['javascript'])->toBeNull()
        ->and($captured['managers'])->toBe([])
        ->and($captured['ssh']['target'])->toBe(['name' => 'target', 'host' => '10.44.0.240',
            'user' => 'orbit', 'port' => 22, 'host_keys' => null])
        ->and($intent['tools']['node_id'])->toBe($node->id)
        ->and($intent['role_bindings'][$role->value]['runtime_material']['vault'])->toBe('orbit-control-inputs')
        ->and(json_encode($intent, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE KEY', 'identityContent', 'worker_token');
})->with([RoleName::Database, RoleName::AppDev, RoleName::AppProd, RoleName::Metrics, RoleName::WebSocket]);
