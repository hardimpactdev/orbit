<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeEnrollmentOperationAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $definition = ['executor' => 'swamp', 'execution' => NodeLifecycleOperationExecution::class, 'completion' => NodeWireGuardOperationCompletion::class,
        'runs' => [['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true]],
        'completion_contract' => ['step' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'result' => ['node_plan_proven' => true]],
        'result_fields' => ['node_plan_proven', 'error_code']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'node.add' => $definition]));
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 enrollment-public');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    $remote = Mockery::mock(AdmissionAgentRunner::class);
    $remote->shouldNotReceive('architectureOver');
    $remote->shouldNotReceive('runOver');
    $remote->shouldNotReceive('installOver');
    app()->instance(AdmissionAgentRunner::class, $remote);
});

it('records a fresh enrollment without installing an agent or claiming an observed identity', function (): void {
    $operation = app(ProvisionNodeAction::class)->start(new ProvisionNodeData(name: 'new-enrollment', publicSshHost: '192.0.2.70',
        user: 'root', orbitUser: 'managed', wireguardIp: '10.44.0.70', expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43),
        roles: [RoleName::AppDev], tld: 'enrollment.test'));
    $node = Node::query()->findOrFail($operation->node_id);

    expect($operation->kind)->toBe('node.add');
    expect($operation->status->value)->toBe('queued');
    expect($operation->lock_key)->toBe('node:'.$node->id);
    expect($operation->input['bootstrap_user'])->toBe('root');
    expect($operation->input['managed_user'])->toBe('managed');
    expect($operation->input['agent_pins'])->toHaveKey('linux-x86_64');
    expect($operation->input['bootstrap_command']['user'])->toBe('managed');
    expect($node->ssh_host_fingerprint)->toBeNull();
    expect($node->architecture)->toBeNull();
    expect($node->roles)->toBeEmpty();
    expect($operation->state['enrollment_intent']['existed'])->toBeFalse();
    expect(array_column($operation->input['_node_plan'], 'key'))->toBe([
        'add-begin', 'add-bootstrap-probe', 'add-bootstrap-install', 'add-bootstrap-host', 'add-bootstrap-verify',
        'add-network', 'add-apt', 'add-role-app-dev', 'add-activate', 'add-footprint', 'add-cli', 'add-commit',
    ]);
});

it('preserves an active identity and omitted role and connection fields at admission', function (): void {
    $node = FleetFixtures::node('existing-enrollment', [RoleName::AppProd]);
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43), 'lan_ip' => '192.0.2.80']);
    $prior = $node->refresh()->attributesToArray();
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: $node->name, publicSshHost: '192.0.2.81',
        architecture: 'arm64'));

    expect($node->fresh()->attributesToArray())->toBe($prior);
    expect($operation->input['requested_architecture'])->toBe('x86_64');
    expect($operation->input['bootstrap_port'])->toBe($node->public_ssh_port);
    expect($operation->state['enrollment_intent']['prior_state']['public_ssh_host'])->toBe($node->public_ssh_host);
    expect($operation->state['enrollment_intent']['roles'])->toBe([]);
    expect($node->roles()->sole()->status->value)->toBe('active');
    expect(fn () => app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: $node->name, publicSshHost: '192.0.2.82')))
        ->toThrow(ResourceOperationException::class);
    expect($node->fresh()->attributesToArray())->toBe($prior);
});

it('stages a populated TLD change as Route children while keeping its existing serving scope', function (): void {
    $node = FleetFixtures::node('tld-enrollment', [RoleName::AppDev]);
    $node->update(['tld' => 'before.test']);
    $project = Project::query()->create(['name' => 'Enrollment TLD', 'slug' => 'enrollment-tld', 'repository_url' => 'https://example.test/tld.git']);
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default', 'checkout_path' => '/srv/tld', 'status' => 'active']);
    $route = Route::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'generation_basis_node_id' => $node->id,
        'domain' => 'enrollment-tld.before.test', 'provenance' => 'generated', 'publication' => 'private']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: $node->name, publicSshHost: '', tld: 'after.test'));

    expect($operation->input['mode'])->toBe('tld_only');
    expect($operation->state['enrollment_intent']['route_changes'])->toBe([['route_id' => $route->id, 'domain' => 'enrollment-tld.after.test', 'node_id' => $node->id]]);
    expect(array_column($operation->input['_node_plan'], 'key'))->toBe(['add-route-'.$route->id, 'add-activate', 'add-footprint', 'add-cli']);
    expect($node->fresh()->tld)->toBe('before.test');
    expect($node->fresh()->status->value)->toBe('active');
    expect($route->fresh()->domain)->toBe('enrollment-tld.before.test');
    expect(fn () => app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: $node->name, publicSshHost: 'changed', tld: 'after.test')))
        ->toThrow(ResourceOperationException::class);
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

it('records Mac enrollment without Linux setup or changed tunnel intent', function (): void {
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: 'mac-enrollment', publicSshHost: '192.0.2.91',
        platform: 'macos', user: 'mini', orbitUser: 'mini', wireguardIp: '10.44.0.91', expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43)));

    expect($operation->input['mode'])->toBe('macos');
    expect(array_column($operation->input['_node_plan'], 'key'))->toBe(['add-begin', 'add-bootstrap-probe', 'add-bootstrap-install', 'add-macos-inspect', 'add-activate']);
    expect($operation->input['bootstrap_command'])->toBe([]);
    expect($operation->input['bootstrap_firewall'])->toBeNull();
});

it('corrects an unpinned roleless peer from observation while retaining its access and tunnel', function (): void {
    $node = FleetFixtures::node('unmanaged-mac', managed: false);
    $consumer = FleetFixtures::node('unmanaged-mac-consumer');
    $consumer->accessibleNodes()->attach($node);
    $operation = app(AdmitNodeEnrollmentOperationAction::class)->execute(new ProvisionNodeData(name: $node->name, publicSshHost: '',
        platform: 'macos', platformProvided: true, user: 'mini', orbitUser: 'mini', expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43)));

    expect($operation->target_id)->toBe($node->id);
    expect($operation->input['requested_architecture'])->toBeNull();
    expect($operation->state['enrollment_intent']['candidate']['architecture'])->toBeNull();
    expect($operation->state['enrollment_intent']['candidate']['platform'])->toBe('macos');
    expect($operation->state['enrollment_intent']['candidate']['wireguard_ip'])->toBe($node->wireguard_ip);
    expect($node->fresh()->platform)->toBe('linux');
    expect($node->fresh()->architecture)->toBe('x86_64');
    expect($consumer->accessibleNodes()->whereKey($node->id)->exists())->toBeTrue();
});

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
    $operation = app(ProvisionNodeAction::class)->start(new ProvisionNodeData(name: 'alias-enrollment', publicSshHost: '192.0.2.99', platform: $platform,
        architecture: $alias, user: $mac ? 'mini' : 'root', orbitUser: $mac ? 'mini' : 'orbit', wireguardIp: '10.44.0.99', expectedSshHostFingerprint: 'SHA256:'.str_repeat('A', 43)));
    expect($operation->input['requested_architecture'])->toBe($canonical);
})->with([['linux', 'amd64', 'x86_64'], ['macos', 'aarch64', 'arm64']]);
