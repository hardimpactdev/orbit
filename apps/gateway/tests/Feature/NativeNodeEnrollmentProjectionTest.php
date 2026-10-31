<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeEnrollmentProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->freezeTime();
    app()->instance(DirectOperation::class, new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['node.add' => ['workflow' => 'operation-node-add-native', 'projection' => NodeEnrollmentProjection::class]];
        }
    }]));
    $this->node = FleetFixtures::node('enrollment-projection');
    $this->node->update(['settings' => ['apps' => ['path' => '/srv/prior'], 't3' => ['connection' => 'retained']]]);
    $this->before = $this->node->refresh()->attributesToArray();
    $this->run = '23df5534-983f-4d3c-9440-5f76885811d5';
    $this->candidate = ['user' => 'candidate', 'wireguard_ip' => '10.44.0.240', 'public_ssh_host' => '192.0.2.240',
        'public_ssh_port' => 22, 'platform' => 'linux', 'architecture' => null, 'cluster_id' => null, 'tld' => null, 'lan_ip' => null];
    $this->operation = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => $this->run],
        'input' => ['intent' => ['node_id' => $this->node->id, 'mode' => 'linux', 'roles' => [], 'route_mutations' => null],
            'execution_secrets' => ['observer_hash' => ['vault' => 'orbit-control-inputs', 'key' => 'admitted-observer']],
            'enrollment' => ['existed' => true, 'prior_state' => $this->before, 'candidate' => $this->candidate,
                'reserved_roles' => [], 'settings_provided' => true, 'settings' => ['apps' => ['path' => '/srv/candidate']]]]]);
    $this->result = ['node_id' => $this->node->id, 'enrolled' => true, 'restored' => false, 'roles' => [], 'routes' => null,
        'observation' => ['architecture' => 'x86_64', 'host_type' => 'ssh-ed25519', 'public_key' => 'captured-public-key',
            'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)], 'observer_digest' => str_repeat('b', 64),
        'wireguard_public_key' => base64_encode(str_repeat('w', 32))];
    $this->data = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $this->result];
});

it('returns the unchanged public input and makes no early identity or settings projection', function (): void {
    $projection = app(NodeEnrollmentProjection::class);
    expect($projection->input($this->operation))->toBe(array_intersect_key($this->operation->input, array_flip(['intent', 'execution_secrets'])));
    $projection->apply($this->operation, [...$this->data, 'status' => 'running', 'result' => []]);
    expect($this->node->refresh()->attributesToArray())->toBe($this->before);
});

it('projects candidate identity and settings only once at native completion', function (): void {
    $projection = app(NodeEnrollmentProjection::class);
    $data = [...$this->data, 'result' => [...$this->result, 'private_key' => 'foreign-private-value']];
    $projection->apply($this->operation, $data);
    expect($this->node->refresh()->wireguard_ip)->toBe('10.44.0.240')
        ->and($this->node->user)->toBe('candidate')
        ->and($this->node->architecture)->toBe('x86_64')
        ->and($this->node->ssh_host_key)->toBe('captured-public-key')
        ->and($this->node->agent_secret_hash)->toBe(str_repeat('b', 64))
        ->and($this->node->settings)->toBe(['apps' => ['path' => '/srv/candidate'], 't3' => ['connection' => 'retained']])
        ->and($this->operation->refresh()->result)->not->toHaveKey('private_key');
    $attributes = $this->node->getAttributes();
    $projection->apply($this->operation, $data);
    expect($this->node->refresh()->getAttributes())->toBe($attributes);
});

it('preserves existing identity and deletes only newly reserved roles after proven restoration', function (): void {
    $existing = NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::Database, 'status' => LifecycleStatus::Active]);
    $reserved = NodeRole::query()->create(['node_id' => $this->node->id, 'role' => RoleName::AppProd, 'status' => LifecycleStatus::Provisioning]);
    $input = $this->operation->input;
    $input['enrollment']['reserved_roles'] = [['assignment_id' => $existing->id, 'created' => false], ['assignment_id' => $reserved->id, 'created' => true]];
    $this->operation->update(['input' => $input]);
    app(NodeEnrollmentProjection::class)->apply($this->operation, [...$this->data, 'status' => 'failed', 'error_code' => 'node.enrollment_failed',
        'result' => ['node_id' => $this->node->id, 'enrolled' => false, 'restored' => true]]);
    expect($this->node->refresh()->attributesToArray())->toBe($this->before)
        ->and($existing->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($reserved->fresh())->toBeNull()
        ->and($this->operation->refresh()->status)->toBe(OperationStatus::Failed);
});

it('records incomplete restoration without projecting prospective identity', function (): void {
    app(NodeEnrollmentProjection::class)->apply($this->operation, [...$this->data, 'status' => 'failed', 'error_code' => 'operation.native_failed',
        'result' => ['node_id' => $this->node->id, 'enrolled' => false, 'restored' => false]]);
    expect($this->node->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($this->node->wireguard_ip)->toBe($this->before['wireguard_ip'])
        ->and($this->node->user)->toBe($this->before['user'])
        ->and($this->node->settings)->toBe($this->before['settings'])
        ->and($this->node->error_code)->toBe('node.reprovision_rollback_failed');
});

it('does not activate a newly reserved Node when native enrollment fails', function (): void {
    $this->node->update(['status' => LifecycleStatus::Provisioning]);
    $input = $this->operation->input;
    $input['enrollment']['existed'] = false;
    $this->operation->update(['input' => $input]);
    app(NodeEnrollmentProjection::class)->apply($this->operation, [...$this->data, 'status' => 'failed', 'error_code' => 'node.enrollment_failed',
        'result' => ['enrolled' => false, 'restored' => true]]);
    expect($this->node->refresh()->status)->toBe(LifecycleStatus::Failed);
});

it('refuses a foreign or contradictory success before committing Operation or Node state', function (string $change): void {
    $result = $this->result;
    $result[$change] = $change === 'node_id' ? $this->node->id + 1 : ($change === 'restored');
    expect(fn () => app(NodeEnrollmentProjection::class)->apply($this->operation, [...$this->data, 'result' => $result]))
        ->toThrow(ResourceOperationException::class);
    expect($this->node->refresh()->attributesToArray())->toBe($this->before)
        ->and($this->operation->refresh()->status)->toBe(OperationStatus::Running);
})->with(['node_id', 'enrolled', 'restored']);

it('rolls back terminal identity and Operation projection if Route recording fails', function (): void {
    $input = $this->operation->input;
    $input['intent']['route_mutations'] = [];
    $this->operation->update(['input' => $input]);
    try {
        app(NodeEnrollmentProjection::class)->apply($this->operation, [...$this->data, 'result' => [...$this->result, 'routes' => ['ready' => false]]]);
        test()->fail('Invalid Route state was recorded.');
    } catch (ResourceOperationException) {
        expect($this->node->refresh()->attributesToArray())->toBe($this->before)
            ->and($this->operation->refresh()->status)->toBe(OperationStatus::Running);
    }
});
