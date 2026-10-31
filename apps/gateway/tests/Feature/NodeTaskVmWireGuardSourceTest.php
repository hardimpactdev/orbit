<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeNetworkProjection;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Nodes\NodeVpnConfigurationSource;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    FleetFixtures::bind();
    $home = config('orbit.home');
    @mkdir($home.'/wireguard', 0700, true);
    file_put_contents($home.'/wireguard/private.key', base64_encode(str_repeat('p', 32)));
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32)));
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'orbit');
    $this->hub = FleetFixtures::node('taskvm-hub', [RoleName::Vpn]);
    $this->hub->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $this->gateway = FleetFixtures::node('taskvm-gateway', [RoleName::Gateway]);
    $this->guest = FleetFixtures::taskVm();
    $this->guest->update(['wireguard_public_key' => base64_encode(str_repeat('g', 32))]);
    $this->vm = TaskVm::query()->where('node_id', $this->guest->id)->sole();
    $contribution = new readonly class(app(DirectOperation::class)->definitions()) implements DirectOperationContribution
    {
        public function __construct(private array $known) {}

        public function definitions(): array
        {
            $definitions = array_diff_key(array_fill_keys(['task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision', 'task_vm.destroy'],
                ['workflow' => 'operation-test-hub', 'projection' => TaskVmEnrollmentProjection::class,
                    'private_material' => ['configuration' => 'node.vpn_configuration']]), $this->known);
            if (isset($definitions['task_vm.destroy'])) {
                $definitions['task_vm.destroy']['projection'] = NodeNetworkProjection::class;
            }

            return $definitions;
        }
    };
    $direct = new DirectOperation([$contribution]);
    app()->instance(DirectOperation::class, $direct);
    app()->instance(DirectOperationPrivateBindings::class, new DirectOperationPrivateBindings($direct));
    $this->snapshot = app(NodeWireGuardInput::class)->captureForTaskVm($this->guest, $this->gateway);
    $this->operation = new Operation(['kind' => 'task_vm.enroll', 'target_type' => 'task_vm', 'target_id' => $this->vm->id,
        'node_id' => $this->gateway->id, 'status' => 'running', 'state' => ['swamp_run_id' => 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5'],
        'input' => ['task_vm_id' => $this->vm->id, 'guest_node_id' => $this->guest->id,
            'enrollment' => ['task_vm_id' => $this->vm->id, 'guest_node_id' => $this->guest->id],
            '_wireguard' => $this->snapshot,
            'configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $this->hub->id],
            'participant_node_ids' => [$this->gateway->id, $this->guest->id, $this->hub->id],
            'private_bindings' => ['configuration' => ['owner_node_id' => $this->hub->id, 'read_node_ids' => [$this->gateway->id], 'write_node_ids' => []]]]]);
});

it('hydrates current hub config and existing pinned SSH custody for exact canonical TaskVM parents', function (string $kind): void {
    $this->operation->kind = $kind;
    if ($kind === 'tasks.provision') {
        $this->operation->target_type = 'task_group';
        $this->operation->target_id = $this->vm->group_id;
    }
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/private/gateway-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $fresh = FleetFixtures::node('taskvm-fresh');
    $fresh->update(['wireguard_public_key' => base64_encode(str_repeat('f', 32))]);
    $source = app(NodeVpnConfigurationSource::class);
    expect($source->ownerId($this->operation))->toBe($this->hub->id);
    $secret = $source->resolve($this->operation, $this->hub->id, '', 'configuration');
    expect($secret->value['snapshot'])->toBe($this->snapshot);
    expect($secret->value['connection'])->toBe(['target' => ['node_id' => $this->hub->id, 'host' => $this->hub->public_ssh_host,
        'port' => $this->hub->public_ssh_port, 'user' => $this->hub->user, 'fingerprint' => $this->hub->ssh_host_fingerprint], 'identity' => '/private/gateway-identity']);
    expect(array_column($secret->value['peers'], 'node_id'))->toContain($fresh->id);
    expect(json_encode($secret))->not->toContain('/private/gateway-identity', base64_encode(str_repeat('p', 32)));
})->with(['task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision']);

it('refuses altered TaskVM hub custody before reading the SSH identity', function (string $case): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
    $operation = $this->operation;
    $input = $operation->input;
    match ($case) {
        'guest' => $input['enrollment']['guest_node_id'] = $this->hub->id,
        'target' => $operation->target_id++,
        'parent kind' => $operation->kind = 'task_vm.create',
        'Node kind' => $operation->kind = 'node.network_converge',
        'executor' => $input['_wireguard']['executor_node_id'] = $this->guest->id,
        'owner' => $input['private_bindings']['configuration']['owner_node_id'] = $this->guest->id,
        'reader' => $input['private_bindings']['configuration']['read_node_ids'] = [$this->guest->id],
        'participant' => $input['participant_node_ids'] = [$this->gateway->id, $this->guest->id],
        'exclusion' => $input['_wireguard']['excluded_node_id'] = $this->guest->id,
        'Gateway participant' => $input['participant_node_ids'] = [$this->guest->id, $this->hub->id],
    };
    $operation->input = $input;
    expect(NodeWireGuardInput::bound($operation))->toBeNull();
    expect(fn () => app(NodeVpnConfigurationSource::class)->resolve($operation, $this->hub->id, '', 'configuration'))
        ->toThrow(ResourceOperationException::class);
})->with(['guest', 'target', 'parent kind', 'Node kind', 'executor', 'owner', 'reader', 'participant', 'exclusion', 'Gateway participant']);

it('preserves exact destroyed TaskVM guest exclusion without expanding standalone Node authority', function (): void {
    $operation = $this->operation;
    $operation->kind = 'task_vm.destroy';
    $input = $operation->input;
    $input['_wireguard']['excluded_node_id'] = $this->guest->id;
    $operation->input = $input;
    expect(NodeWireGuardInput::bound($operation))->not->toBeNull();
    $operation->kind = 'node.remove';
    $operation->target_type = 'node';
    $operation->target_id = $this->guest->id;
    expect(NodeWireGuardInput::bound($operation))->toBeNull();
});

it('serves hub SSH material only to the immutable Gateway reader through the shared private endpoint', function (): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->once()->andReturn('/private/gateway-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $operation = $this->operation;
    $this->vm->update(['provider' => 'incus', 'host_node_id' => $this->gateway->id]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->gateway->id)]]);
    config(['dependency_detection.services_node_id' => $this->gateway->id, 'swamp_workers.nodes' => [$this->hub->id, $this->gateway->id]]);
    $operation->kind = 'task_vm.destroy';
    $operation->input = app(TaskVmDestroyIntent::class)->capture($this->vm->fresh());
    $operation->save();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $query = '?run_id='.$operation->state['swamp_run_id'];

    $this->withServerVariables(['REMOTE_ADDR' => $this->guest->wireguard_ip])
        ->getJson($path.'/private-material'.$query)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->hub->wireguard_ip])
        ->getJson($path.'/private-material'.$query)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
        ->getJson($path.'/private-material'.$query)->assertOk()
        ->assertJsonPath('data.configuration.connection.target.node_id', $this->hub->id)
        ->assertJsonPath('data.configuration.connection.identity', '/private/gateway-identity');
    $this->getJson($path.$query)->assertOk()
        ->assertDontSee('/private/gateway-identity', false)
        ->assertDontSee(base64_encode(str_repeat('p', 32)), false);
});

it('selects the admitted restore hub view and fresh peer data through the same private field', function (): void {
    $operation = $this->operation;
    $operation->kind = 'task_vm.destroy';
    $input = $operation->input;
    $input['_wireguard']['excluded_node_id'] = $this->guest->id;
    $input['_wireguard_restore'] = NodeWireGuardInput::restorationSnapshot($input['_wireguard']);
    $operation->input = $input;
    $operation->save();
    $this->guest->update(['status' => LifecycleStatus::Removing]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->twice()->andReturn('/private/gateway-identity');
    app()->instance(SshKeyProvider::class, $keys);
    $source = app(NodeVpnConfigurationSource::class);
    $cleanup = $source->resolve($operation, $this->hub->id, '', 'configuration')->value;
    expect($cleanup['snapshot']['excluded_node_id'])->toBe($this->guest->id);
    expect(array_column($cleanup['peers'], 'node_id'))->not->toContain($this->guest->id);
    $fresh = FleetFixtures::node('restore-fresh-peer');
    $fresh->update(['wireguard_public_key' => base64_encode(str_repeat('r', 32))]);
    DB::transaction(function () use ($operation): void {
        $this->guest->update(['status' => LifecycleStatus::Active]);
        NodeWireGuardInput::beginRestoration($operation);
    });
    $restored = $source->resolve($operation->fresh(), $this->hub->id, '', 'configuration')->value;
    expect($operation->fresh()->input)->toBe($input);
    expect($restored['snapshot'])->toBe($input['_wireguard_restore']);
    expect(array_column($restored['peers'], 'node_id'))->toContain($this->guest->id, $fresh->id);
    expect($restored['connection'])->toBe($cleanup['connection']);
});

it('refuses malformed or unowned hub restoration before selecting another private view', function (string $case): void {
    $operation = $this->operation;
    $operation->kind = 'task_vm.destroy';
    $input = $operation->input;
    $input['_wireguard']['excluded_node_id'] = $this->guest->id;
    $input['_wireguard_restore'] = [...$input['_wireguard'], 'excluded_node_id' => null];
    match ($case) {
        'missing' => $input['_wireguard_restore'] = null,
        'hub' => $input['_wireguard_restore']['hub_id'] = $this->guest->id,
        'executor' => $input['_wireguard_restore']['executor_node_id'] = $this->guest->id,
        'context' => $input['_wireguard_restore']['context_node_id'] = $this->hub->id,
        'pin' => $input['_wireguard_restore']['agent_sha256'] = str_repeat('f', 64),
        'excluded' => $input['_wireguard_restore']['excluded_node_id'] = $this->guest->id,
        'kind' => $operation->kind = 'task_vm.enroll',
        'terminal' => $operation->status = 'failed',
        'removing' => $this->guest->update(['status' => LifecycleStatus::Removing]),
    };
    $operation->input = $input;
    $operation->save();
    expect(fn () => NodeWireGuardInput::beginRestoration($operation))->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->state)->not->toHaveKey('node_wireguard_restoring');
    $operation->state = [...$operation->state, 'node_wireguard_restoring' => true];
    if ($case !== 'terminal') {
        expect(NodeWireGuardInput::bound($operation))->toBeNull();
    }
})->with(['missing', 'hub', 'executor', 'context', 'pin', 'excluded', 'kind', 'terminal', 'removing']);
