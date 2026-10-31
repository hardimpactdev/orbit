<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsFleetProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\OperationStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'fleet-direct-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->node = Node::query()->forceCreate(['id' => 9, 'name' => 'metrics', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'fixture']);
    $this->node->roles()->create(['role' => 'metrics', 'status' => 'active']);
    config(['swamp_workers.nodes' => [9]]);
    $this->plan = app(MetricsFleetCapture::class)->capture();
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => 9, 'node_id' => 9, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['swamp_run_id' => $this->run],
        'input' => ['participant_node_ids' => [9], 'metrics_fleet' => $this->plan, 'fleet_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => 9]]]);
    $this->credentials = Mockery::mock(MetricsCredentialManager::class);
    app()->instance(MetricsCredentialManager::class, $this->credentials);
    $this->result = ['ready' => true, 'missing_worker_ids' => [], 'verified_node_ids' => [9], 'restored_node_ids' => [], 'unknown_node_ids' => [], 'runtime_committed' => true];
    $this->completion = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00Z', 'finished_at' => '2026-10-31T03:00:01Z', 'error_code' => null, 'result' => $this->result];
});
afterEach(function (): void {
    unlink($this->manifest);
});

describe('same-root Node parent runtime custody', function (): void {
    beforeEach(function (): void {
        $this->parentNode = Node::query()->forceCreate(['id' => 14, 'name' => 'parent', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']);
        $this->plan = app(MetricsFleetCapture::class)->capture();
        $direct = app(DirectOperation::class);
        $definitions = $direct->definitions();
        foreach (['node.role_add', 'node.role_converge', 'node.role_remove', 'node.role_relocate', 'node.remove'] as $kind) {
            $definitions[$kind] = ['workflow' => 'fixture-node-parent', 'projection' => MetricsFleetProjection::class,
                'private_material' => ['metrics_runtime' => 'metrics.fleet_runtime']];
        }
        $reflection = new ReflectionClass($direct);
        $isolated = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('definitions')->setValue($isolated, $definitions);
        app()->instance(DirectOperation::class, $isolated);
        $this->scope = new SettingScope(SettingScopeType::Node, 9);
        app(SettingRepository::class)->put($this->scope, NativeMetricsCredentialManager::ActivePasswordKey, 'existing-parent-private-sentinel', SettingValueProtection::Secret);
        $this->credentials->shouldNotReceive('passwordForConvergence');
        $this->operation->update(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => 14, 'node_id' => 14,
            'input' => ['participant_node_ids' => [9, 14], '_role' => ['metrics_fleet' => $this->plan],
                'metrics_runtime' => ['$secret' => 'metrics.fleet_runtime', 'id' => 9],
                'private_bindings' => ['metrics_runtime' => ['owner_node_id' => 9, 'read_node_ids' => [9], 'write_node_ids' => []]]]]);
    });

    it('hydrates each exact parent capture only on the Metrics owner without minting or persisting material', function (string $kind, string $capture, bool $restoring = false): void {
        $input = $this->operation->input;
        unset($input['_role']);
        $input[$capture] = [$restoring ? 'restore_metrics_fleet' : 'metrics_fleet' => $this->plan];
        $this->operation->update(['kind' => $kind, 'input' => $input, 'result' => $restoring ? ['restoring' => true] : null]);
        $before = $this->operation->fresh()->getAttributes();
        $material = app(DirectOperationPrivateMaterial::class)->read($this->operation, 9)['metrics_runtime'];
        expect($material['runtime']['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe('existing-parent-private-sentinel')
            ->and($material['runtime']['configuration']['/etc/orbit/metrics/prometheus.yml'])->toBe($this->plan['runtime_files']['/etc/orbit/metrics/prometheus.yml'])
            ->and($material['verify'])->toBe(['operation' => 'verify', 'password' => 'existing-parent-private-sentinel'])
            ->and($this->operation->fresh()->getAttributes())->toBe($before);
        expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))->not->toContain('existing-parent-private-sentinel');
        expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation, 14))->toThrow(ResourceOperationException::class);
    })->with([
        ['node.role_add', '_role'], ['node.role_converge', '_role'], ['node.role_remove', '_role_remove'],
        ['node.role_relocate', '_relocate'], ['node.remove', '_removal'], ['node.remove', '_removal', true],
    ]);

    it('refuses wrong owner readers writers references paths assignments and owner drift', function (string $change): void {
        $input = $this->operation->input;
        if ($change === 'owner') {
            $input['private_bindings']['metrics_runtime']['owner_node_id'] = 14;
        } elseif ($change === 'reader') {
            $input['private_bindings']['metrics_runtime']['read_node_ids'][] = 14;
        } elseif ($change === 'writer') {
            $input['private_bindings']['metrics_runtime']['write_node_ids'] = [9];
        } elseif ($change === 'reference') {
            $input['metrics_runtime']['id'] = 14;
        } elseif ($change === 'path') {
            $input['_role_remove'] = $input['_role'];
            unset($input['_role']);
        } elseif ($change === 'assignment') {
            $this->node->roles()->where('role', 'metrics')->update(['status' => 'failed']);
        } elseif ($change === 'address') {
            $this->node->update(['wireguard_ip' => '10.44.0.99']);
        } elseif ($change === 'unbound') {
            $this->operation->update(['state' => []]);
        } else {
            $input['participant_node_ids'] = [14];
        }
        $this->operation->update(['input' => $input]);
        expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation, 9))->toThrow(ResourceOperationException::class);
    })->with(['owner', 'reader', 'writer', 'reference', 'path', 'assignment', 'address', 'unbound', 'participant']);

    it('uses the admitted post-transition capture instead of silently replacing it with current fleet intent', function (): void {
        $plan = app(MetricsFleetCapture::class)->captureRetiring($this->parentNode);
        $input = $this->operation->input;
        unset($input['_role']);
        $input['_removal'] = ['metrics_fleet' => $plan];
        $this->operation->update(['kind' => 'node.remove', 'input' => $input]);
        $material = app(DirectOperationPrivateMaterial::class)->read($this->operation, 9)['metrics_runtime'];
        expect($material['runtime']['configuration']['/etc/orbit/metrics/prometheus.yml'])->toBe($plan['runtime_files']['/etc/orbit/metrics/prometheus.yml']);
    });

    it('refuses a missing existing password without creating a replacement', function (): void {
        app(SettingRepository::class)->delete($this->scope, NativeMetricsCredentialManager::ActivePasswordKey);
        expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation, 9))->toThrow(ResourceOperationException::class);
        expect(app(SettingRepository::class)->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
    });

    it('initializes the existing credential type once for its exact provisioning Metrics root', function (string $kind, string $capture): void {
        app(SettingRepository::class)->delete($this->scope, NativeMetricsCredentialManager::ActivePasswordKey);
        $this->node->roles()->where('role', 'metrics')->update(['status' => 'provisioning']);
        app()->instance(MetricsCredentialManager::class, app(NativeMetricsCredentialManager::class));
        $input = $this->operation->input;
        unset($input['_role']);
        $input[$capture] = ['metrics_fleet' => $this->plan];
        $this->operation->update(['kind' => $kind, 'target_id' => 9, 'node_id' => 9, 'input' => [...$input, 'role' => 'metrics']]);
        $reader = app(DirectOperationPrivateMaterial::class);
        $first = $reader->read($this->operation, 9)['metrics_runtime'];
        $second = $reader->read($this->operation, 9)['metrics_runtime'];
        $password = app(SettingRepository::class)->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey);
        expect($password)->toBeString()->toHaveLength(32);
        expect($second)->toBe($first)
            ->and($first['runtime']['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe($password)
            ->and($first['verify'])->toBe(['operation' => 'verify', 'password' => $password]);
        expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))->not->toContain($password);
    })->with([['node.role_add', '_role'], ['node.role_converge', '_role'], ['node.role_relocate', '_relocate']]);

    it('never bootstraps an active misplaced removal or different-role root', function (string $change): void {
        app(SettingRepository::class)->delete($this->scope, NativeMetricsCredentialManager::ActivePasswordKey);
        $this->node->roles()->where('role', 'metrics')->update(['status' => $change === 'active' ? 'active' : 'provisioning']);
        $input = [...$this->operation->input, 'role' => $change === 'role' ? 'app-dev' : 'metrics'];
        $kind = 'node.role_add';
        if ($change === 'removal') {
            $kind = 'node.role_remove';
            $input['_role_remove'] = $input['_role'];
            unset($input['_role']);
        }
        $this->operation->update(['kind' => $kind, 'target_id' => $change === 'target' ? 14 : 9,
            'node_id' => $change === 'placement' ? 14 : 9, 'input' => $input]);
        expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation, 9))->toThrow(ResourceOperationException::class);
        expect(app(SettingRepository::class)->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBeNull();
    })->with(['active', 'role', 'target', 'placement', 'removal']);

    it('keeps an existing provisioning-owner credential and refuses an empty one instead of replacing it', function (): void {
        $this->node->roles()->where('role', 'metrics')->update(['status' => 'provisioning']);
        $this->operation->update(['target_id' => 9, 'node_id' => 9, 'input' => [...$this->operation->input, 'role' => 'metrics']]);
        expect(app(DirectOperationPrivateMaterial::class)->read($this->operation, 9)['metrics_runtime']['verify']['password'])->toBe('existing-parent-private-sentinel');
        app(SettingRepository::class)->put($this->scope, NativeMetricsCredentialManager::ActivePasswordKey, '', SettingValueProtection::Secret);
        expect(fn () => app(DirectOperationPrivateMaterial::class)->read($this->operation, 9))->toThrow(ResourceOperationException::class);
        expect(app(SettingRepository::class)->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe('');
    });

});
