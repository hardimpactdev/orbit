<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsExporterProjection;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'metrics-enrollment-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    config(['swamp_workers.nodes' => [9, 14]]);
});
afterEach(function (): void {
    unlink($this->manifest);
});

function metricsEnrollmentFixture(bool $foreignOwner = false): array
{
    foreach ([1, 9, 14, 18] as $id) {
        $nodes[$id] = Node::query()->forceCreate(['id' => $id, 'name' => 'enrollment-'.$id,
            'status' => $id === 9 ? 'provisioning' : 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'wireguard_ip' => $id === 9 ? null : '10.44.0.'.$id, 'user' => 'old-user',
            'public_ssh_host' => '192.0.2.'.$id, 'public_ssh_port' => 22, 'ssh_host_fingerprint' => 'managed-fixture']);
    }
    $guest = $nodes[9];
    $owner = $foreignOwner ? $nodes[14] : $guest;
    $assignment = $owner->roles()->create(['role' => 'metrics', 'status' => $foreignOwner ? 'active' : 'provisioning']);
    $guest->roles()->firstOrCreate(['role' => 'database'], ['status' => 'provisioning']);
    $nodes[1]->roles()->create(['role' => 'vpn', 'status' => 'active']);
    app(ExporterPreferenceRepository::class)->put(18, ExporterPreference::Enabled);
    $candidate = ['user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9',
        'cluster_id' => null, 'tld' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'public_ssh_port' => 22,
        'lan_ip' => null, 'wireguard_endpoint_override' => null, 'dns_server_override' => null, 'ssh_jump_node_id' => null];
    $render = clone $guest;
    $render->forceFill($candidate);
    $render->status = LifecycleStatus::Active;
    $plan = app(MetricsFleetCapture::class)->captureForEnrollment($render);
    $direct = app(DirectOperation::class);
    $definitions = $direct->definitions();
    $definitions['node.add'] = ['workflow' => 'fixture-enrollment', 'projection' => MetricsEnrollmentFixtureProjection::class,
        'private_material' => ['metrics_runtime' => 'metrics.fleet_runtime']];
    $reflection = new ReflectionClass($direct);
    $isolated = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($isolated, $definitions);
    app()->instance(DirectOperation::class, $isolated);
    $input = ['participant_node_ids' => [1, 9, 14, 18], '_enrollment' => ['schema_version' => 1,
        'node_id' => 9, 'executor_node_id' => 14, 'candidate' => $candidate, 'roles' => $foreignOwner ? ['database'] : ['database', 'metrics'],
        'role_snapshots' => $foreignOwner ? [] : [['schema_version' => 1, 'node_id' => 9, 'assignment_id' => $assignment->id,
            'role' => 'metrics', 'user' => 'orbit', 'agent_sha256' => str_repeat('a', 64)]], 'metrics_fleet' => $plan],
        'metrics_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => $owner->id],
        'private_bindings' => ['metrics_runtime' => ['owner_node_id' => $owner->id, 'read_node_ids' => [$owner->id], 'write_node_ids' => []]]];
    $operation = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => 9,
        'node_id' => 14, 'status' => 'running', 'sequence' => 1, 'input' => $input,
        'state' => ['swamp_run_id' => '405f00a0-9537-40af-8b21-4cfe4815a1ee']]);

    return compact('guest', 'owner', 'assignment', 'render', 'plan', 'operation');
}

final readonly class MetricsEnrollmentFixtureProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return [];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $operation;
    }
}

it('captures the candidate and complete fleet without persisting candidate identity or changing normal selection', function (): void {
    $fixture = metricsEnrollmentFixture();
    $members = array_column($fixture['plan']['fleet'], null, 'node_id');
    expect(array_keys($members))->toBe([1, 9, 14, 18])
        ->and($members[1]['worker'])->toBeFalse()->and($members[18]['worker'])->toBeFalse()
        ->and($members[9]['address'])->toBe('10.44.0.9')->and($members[9]['selected'])->toBeTrue()
        ->and($members[9]['selection_reason'])->toBe('metrics_node')
        ->and($fixture['plan']['runtime_files']['/etc/orbit/metrics/prometheus.yml'])->toContain('10.44.0.9', 'enrollment-18')
        ->and($fixture['plan']['runtime_files'])->not->toHaveKey('/etc/orbit/metrics/grafana/admin-password');
    $stored = $fixture['guest']->fresh();
    expect($stored->status)->toBe(LifecycleStatus::Provisioning)->and($stored->wireguard_ip)->toBeNull()->and($stored->user)->toBe('old-user');
    expect(app(NativeMetricsExporterProjection::class)->forNode($fixture['render'], $fixture['guest']))->toBeNull();
});

it('hydrates and initializes only the exact Provisioning Metrics guest through the real shared reader', function (): void {
    $fixture = metricsEnrollmentFixture();
    $reader = app(DirectOperationPrivateMaterial::class);
    $before = $fixture['operation']->fresh()->getAttributes();
    $first = $reader->read($fixture['operation'], 9)['metrics_runtime'];
    $second = $reader->read($fixture['operation'], 9)['metrics_runtime'];
    $password = app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, 9), NativeMetricsCredentialManager::ActivePasswordKey);
    expect($password)->toBeString()->toHaveLength(32)
        ->and($second)->toBe($first)->and($first['verify']['password'])->toBe($password)
        ->and($first['runtime']['configuration']['/etc/orbit/metrics/prometheus.yml'])->toBe($fixture['plan']['runtime_files']['/etc/orbit/metrics/prometheus.yml'])
        ->and($fixture['operation']->fresh()->getAttributes())->toBe($before)
        ->and($fixture['guest']->fresh()->wireguard_ip)->toBeNull();
    expect(json_encode([$fixture['operation']->fresh()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))->not->toContain($password);
    expect(fn () => $reader->read($fixture['operation'], 14))->toThrow(ResourceOperationException::class);
});

it('uses an existing foreign Active owner credential without granting the guest private access', function (): void {
    $fixture = metricsEnrollmentFixture(true);
    $scope = new SettingScope(SettingScopeType::Node, 14);
    $settings = app(SettingRepository::class);
    $settings->put($scope, NativeMetricsCredentialManager::ActivePasswordKey, 'existing-owner-private-sentinel', SettingValueProtection::Secret);
    $reader = app(DirectOperationPrivateMaterial::class);
    expect($reader->read($fixture['operation'], 14)['metrics_runtime']['verify']['password'])->toBe('existing-owner-private-sentinel');
    expect(fn () => $reader->read($fixture['operation'], 9))->toThrow(ResourceOperationException::class);
    $settings->delete($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    expect(fn () => $reader->read($fixture['operation'], 14))->toThrow(ResourceOperationException::class);
    expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
});

it('refuses contradictory enrollment evidence before credential initialization', function (string $change): void {
    $fixture = metricsEnrollmentFixture();
    $operation = $fixture['operation'];
    $input = $operation->input;
    match ($change) {
        'digest' => $input['_enrollment']['metrics_fleet']['digest'] = str_repeat('0', 64),
        'candidate-address' => $input['_enrollment']['candidate']['wireguard_ip'] = '10.44.0.99',
        'candidate-extra' => $input['_enrollment']['candidate']['unadmitted'] = true,
        'role' => $input['_enrollment']['roles'] = ['database'],
        'snapshot' => $input['_enrollment']['role_snapshots'] = [],
        'assignment' => $input['_enrollment']['role_snapshots'][0]['assignment_id'] = $fixture['assignment']->id + 1,
        'pin' => $input['_enrollment']['role_snapshots'][0]['agent_sha256'] = str_repeat('b', 64),
        'user' => $input['_enrollment']['role_snapshots'][0]['user'] = 'another-user',
        'executor' => $input['_enrollment']['executor_node_id'] = 9,
        'target' => $input['_enrollment']['node_id'] = 14,
        'schema' => $input['_enrollment']['schema_version'] = 2,
        'path' => $input['_enrollment']['metrics_fleet'] = null,
        'participant' => $input['participant_node_ids'] = [9, 14, 18],
        'reader' => $input['private_bindings']['metrics_runtime']['read_node_ids'] = [9, 14],
        'writer' => $input['private_bindings']['metrics_runtime']['write_node_ids'] = [9],
        'reference' => $input['metrics_runtime']['id'] = 14,
        default => throw new LogicException,
    };
    $operation->update(['input' => $input]);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($operation, 9))->toThrow(ResourceOperationException::class);
    expect(app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, 9), NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
})->with(['digest', 'candidate-address', 'candidate-extra', 'role', 'snapshot', 'assignment', 'pin', 'user', 'executor', 'target', 'schema', 'path', 'participant', 'reader', 'writer', 'reference']);

it('refuses inactive enrollment owners and assignments without creating credentials', function (string $change): void {
    $fixture = metricsEnrollmentFixture();
    if ($change === 'assignment') {
        $fixture['assignment']->update(['status' => 'removing']);
    } else {
        $fixture['guest']->update(['status' => $change]);
    }
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($fixture['operation'], 9))->toThrow(ResourceOperationException::class);
    expect(app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, 9), NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
})->with(['failed', 'removing', 'assignment']);

it('never replaces an empty recorded password during enrollment', function (): void {
    $fixture = metricsEnrollmentFixture();
    $settings = app(SettingRepository::class);
    $scope = new SettingScope(SettingScopeType::Node, 9);
    $settings->put($scope, NativeMetricsCredentialManager::ActivePasswordKey, '', SettingValueProtection::Secret);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($fixture['operation'], 9))->toThrow(ResourceOperationException::class);
    expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe('');
});

it('refuses a foreign Provisioning owner or an Active owner with address drift', function (string $change): void {
    $fixture = metricsEnrollmentFixture(true);
    $fixture['owner']->update($change === 'provisioning' ? ['status' => 'provisioning'] : ['wireguard_ip' => '10.44.0.99']);
    expect(fn () => app(DirectOperationPrivateMaterial::class)->read($fixture['operation'], 14))->toThrow(ResourceOperationException::class);
    expect(app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, 14), NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
})->with(['provisioning', 'address']);

it('uses the canonical selector for the guest when the Metrics owner is foreign', function (): void {
    $fixture = metricsEnrollmentFixture(true);
    $members = array_column($fixture['plan']['fleet'], null, 'node_id');
    expect(array_keys($members))->toBe([1, 9, 14, 18])->and($members[9]['selected'])->toBeTrue()
        ->and($members[9]['selection_reason'])->toBe('role_default')->and($members[9]['address'])->toBe('10.44.0.9');
});
