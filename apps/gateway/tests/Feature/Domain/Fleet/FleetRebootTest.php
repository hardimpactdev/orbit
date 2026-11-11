<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitFleetRebootAction;
use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\GatewayReleases\AdmitSwampGatewayReleaseAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Fleet\FleetRebootAlerts;
use App\Domain\Fleet\FleetRebootProjection;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofVerifyIntent;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskVmAwake;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Jobs\Fleet\CheckFleetRebootConfirmation;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** @param list<RoleName> $roles */
function rebootNode(string $name, array $roles, array $extra = []): Node
{
    static $address = 40;
    $address++;
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => '192.0.2.'.$address,
        'wireguard_ip' => '10.44.0.'.$address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:'.$name, 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => $name.'-key', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', ...$extra]));
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
    }

    return $node->load('roles');
}

/** A terminal pass with the given Node records. @param list<array<string, mixed>> $nodes */
function rebootPass(array $nodes, string $status = 'succeeded', ?CarbonInterface $finished = null): Operation
{
    return Operation::query()->create(['kind' => FleetReboot::Kind, 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => $status,
        'input' => ['intent' => ['order' => array_column($nodes, 'node_id')]], 'result' => ['nodes' => $nodes], 'finished_at' => $finished ?? now()]);
}

beforeEach(function (): void {
    config(['fleet.reboot' => true, 'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $this->swamp = SwampNodeFixture::ensure()->load('roles');
    $this->gateway = rebootNode('gateway', [RoleName::Gateway]);
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->vpn = rebootNode('vpn', [RoleName::Vpn]);
    $this->prod = rebootNode('app-prod', [RoleName::AppProd]);
    $this->beast = rebootNode('beast', [RoleName::AppDev, RoleName::Database, RoleName::CiRunner],
        ['settings' => ['task_vms' => ['cidr' => '10.252.7.0/24', 'task_places' => 4, 'ci_places' => 2, 'project' => 'orbit-tasks', 'network' => 'orbittask0',
            'pool' => 'orbit-tasks', 'image_alias' => 'orbit-task-base', 'zfs_dataset' => null, 'cpus' => 4, 'memory' => '8GiB', 'disk' => '20GiB', 'pool_size' => '640GiB']]]);
    $this->operator = rebootNode('usexit', []);
    $this->sent = [];
    app()->instance(ReleaseAlertNotifier::class, new class($this) implements ReleaseAlertNotifier
    {
        public function __construct(private object $test) {}

        public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
        {
            $this->test->sent[] = $alert;

            return new ReleaseAlertReceipt('request', null, ReleaseAlertStep::skipped('test'), ReleaseAlertStep::skipped('test'));
        }
    });
});

it('orders the pass by risk and puts the control path, then the swamp Node, last', function (): void {
    $names = array_map(static fn (Node $node): string => $node->name, app(FleetReboot::class)->members());

    // Roleless managed Nodes go with app-dev; beast (ci-runner, database) in 3; app-prod in 4; vpn and the Gateway in 5; swamp in 6.
    expect($names)->toBe(['usexit', 'beast', 'app-prod', 'gateway', 'vpn', 'swamp-fixture']);
});

it('leaves out macOS, unmanaged and task VM Nodes', function (): void {
    rebootNode('mac', [], ['platform' => 'macos']);
    rebootNode('unpinned', [RoleName::AppDev], ['ssh_host_fingerprint' => null]);
    $guest = rebootNode('beast-tvm-1', [RoleName::AppDev]);
    TaskVm::query()->create(['host_node_id' => $this->beast->id, 'node_id' => $guest->id, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.151',
        'provider' => 'incus', 'capacity_pool' => 'task']);

    expect(array_map(static fn (Node $node): string => $node->name, app(FleetReboot::class)->members()))->not->toContain('mac', 'unpinned', 'beast-tvm-1');
});

it('gives Swamp what each Node needs to drain: task project, CI project and the Gateway units', function (): void {
    $reboot = app(FleetReboot::class);

    expect($reboot->node($this->beast))->toMatchArray(['kind' => 'node', 'task_project' => 'orbit-tasks', 'ci_project' => 'orbit-ci', 'gateway' => null,
        'host' => $this->beast->wireguard_ip, 'host_keys' => ['ssh-ed25519 beast-key']])
        ->and($reboot->node($this->gateway)['gateway'])->toMatchArray(['scheduler' => 'orbit-gateway-schedule.service'])
        ->and($reboot->node($this->swamp)['kind'])->toBe('swamp')
        ->and($reboot->node($this->prod))->toMatchArray(['task_project' => null, 'ci_project' => null]);
});

it('requires the agent everywhere, the Gateway scheduler and Horizon, and the hub tunnel with its source unit after the boot', function (): void {
    $reboot = app(FleetReboot::class);

    expect($reboot->node($this->prod)['services'])->toBe(['orbit-agent.service'])
        ->and($reboot->node($this->gateway)['services'])->toBe(['orbit-agent.service', 'orbit-gateway-schedule.service', ...(GatewayQueueRedis::configured() ? ['orbit-gateway-horizon.service'] : [])])
        // The hub's public endpoint is an IPv4 address, so it has the source unit.
        ->and($reboot->node($this->vpn)['services'])->toBe(['orbit-agent.service', 'wg-quick@orbit.service', NodeWireGuardHubInput::SourceUnit]);
});

it('only reads a task host with open task work, without holding its work, and admits the others with the window', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    $group = Task::query()->create(['project_id' => null, 'title' => 'g', 'brief' => 'b', 'status' => 'running']);
    $vm = TaskVm::query()->create(['host_node_id' => $this->beast->id, 'group_id' => $group->id, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.152',
        'provider' => 'incus', 'capacity_pool' => 'task']);
    Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => null,
        'status' => 'running', 'input' => []]);
    config(['swamp.run_token' => 'test-token', 'swamp.read_token' => 'read-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
        return [['type' => 'event', 'id' => end($frames)['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-fleet-reboot', 'runId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
    });
    app()->instance(WebSocketClient::class, $socket);

    $operation = app(AdmitFleetRebootAction::class)->execute(window: true);

    expect($operation->kind)->toBe('fleet.reboot')
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input['intent']['window'])->toBe(['timezone' => 'Europe/Amsterdam', 'start' => '03:00', 'end' => '06:00', 'enforced' => true])
        ->and($operation->input['intent']['drain_seconds'])->toBe(900)
        ->and($operation->input['intent']['ci_repositories'])->toBe(FleetReboot::ciRepositories())
        ->and($operation->input['intent']['order'])->toContain($this->beast->id)
        ->and($operation->input['intent']['nodes'][(string) $this->beast->id]['defer'])->toBe('tasks_busy')
        ->and($operation->input['intent']['nodes'][(string) $this->prod->id]['defer'])->toBeNull()
        ->and($operation->input['task_hosts'])->toBe([])
        ->and($vm->refresh()->state)->toBe(TaskVmState::Ready)
        ->and($frames)->toBe([]);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $operation->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    app()->call([new StartAdmittedSwampRun($operation->id), 'handle']);

    expect($frames[0]['payload']['workflowIdOrName'])->toBe('operation-fleet-reboot')
        ->and($frames[0]['payload']['inputs']['intent'])->toBe($operation->input['intent']);
});

it('never overlaps a rollout or a Gateway release, and the rollout waits for the pass', function (): void {
    $rollout = Operation::query()->create(['kind' => 'fleet.rollout', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => 'running', 'input' => []]);
    expect(fn () => app(AdmitFleetRebootAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.reboot_waiting'));

    $rollout->update(['status' => 'succeeded']);
    Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => 'running',
        'input' => ['task_hosts' => [$this->beast->id]]]);
    expect(fn () => app(AdmitFleetRebootAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.reboot_busy'))
        ->and(fn () => app(AdmitFleetRolloutAction::class)->execute())
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fleet.rollout_busy'))
        ->and(fn () => app(AdmitSwampGatewayReleaseAction::class)->execute(str_repeat('a', 40)))
        ->toThrow(fn (GatewayReleaseException $exception) => expect($exception->errorCode)->toBe('gateway.release_in_progress'));
});

it('holds task work and new task VMs off a host while its pass runs', function (): void {
    $group = Task::query()->create(['project_id' => null, 'title' => 'g', 'brief' => 'b', 'status' => 'running']);
    TaskVm::query()->create(['host_node_id' => $this->beast->id, 'group_id' => $group->id, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.153',
        'provider' => 'incus', 'capacity_pool' => 'task']);
    Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => 'running',
        'input' => ['task_hosts' => [$this->beast->id]]]);

    expect(fn () => TaskVmAwake::require($group))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('tasks.host_rebooting'))
        ->and(array_map(static fn (array $host): int => $host[1]->id, app(TaskVmCapacity::class)->activeHosts()))->not->toContain($this->beast->id);
});

it('records each Node in pass order, parks the stopped task VMs and alerts on the failed Node', function (): void {
    $vm = TaskVm::query()->create(['host_node_id' => $this->beast->id, 'name' => 'beast-tvm-2', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.154',
        'provider' => 'incus', 'capacity_pool' => 'task']);
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => $this->swamp->id,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-1'], 'input' => ['intent' => ['order' => [$this->beast->id, $this->prod->id, $this->vpn->id]],
            'task_hosts' => [$this->beast->id]]]);

    $projected = app(FleetRebootProjection::class)->apply($operation, ['run_id' => 'run-1', 'status' => 'failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => 'fleet.reboot_check_failed', 'failure' => null, 'result' => ['nodes' => [
            ['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'rebooted', 'parked' => ['beast-tvm-2'], 'kernel_after' => '7.0.0-43-generic'],
            ['node_id' => $this->prod->id, 'name' => 'app-prod', 'outcome' => 'failed', 'step' => 'check', 'error_code' => 'fleet.reboot_units_failed',
                'boot_id_before' => 'boot-a', 'failed_units' => ['caddy.service']],
        ]]]);

    expect(array_column($projected->result['nodes'], 'outcome', 'node_id'))->toBe([$this->beast->id => 'rebooted', $this->prod->id => 'failed',
        $this->vpn->id => 'skipped'])
        ->and($projected->result['error_code'])->toBe('fleet.reboot_units_failed')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Parked)
        ->and(array_map(static fn (ReleaseAlert $alert): string => $alert->incident->key($alert->kind), $this->sent))
        ->toBe(['reboot_halted|fleet-node:'.$this->prod->id.'|boot-a|fleet.reboot_units_failed']);
});

it('keeps an unreachable Node failure and diagnostic, skips later Nodes and alerts once, also with a reboot hold', function (bool $held): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T02:10:00Z'));
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => $this->swamp->id,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-unreachable'], 'input' => ['intent' => [
            'order' => [$this->beast->id, $this->prod->id, $this->vpn->id],
            'nodes' => [$this->beast->id => ['defer' => $held ? 'operator_hold' : null]],
        ], 'task_hosts' => []]]);
    $diagnostic = 'Failed to connect to the host via ssh: ssh: connect to host 192.0.2.9 port 22: Connection timed out';
    $data = ['run_id' => 'run-unreachable', 'status' => 'failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => 'operation.native_failed', 'failure' => null, 'result' => ['nodes' => [
            ['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'failed', 'step' => 'drain',
                'error_code' => 'fleet.node_unreachable', 'error_detail' => $diagnostic],
            ['node_id' => $this->prod->id, 'outcome' => 'skipped', 'reason' => 'halted'],
        ]]];

    $projected = app(FleetRebootProjection::class)->apply($operation, $data);
    app(FleetRebootProjection::class)->apply($projected, $data);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->result['error_code'])->toBe('fleet.node_unreachable')
        ->and($projected->result['nodes'][0])->toMatchArray(['outcome' => 'failed', 'error_code' => 'fleet.node_unreachable',
            'step' => 'drain', 'error_detail' => $diagnostic, 'reboot_hold' => $held])
        ->and($projected->result['nodes'][1])->toMatchArray(['outcome' => 'skipped', 'reason' => 'halted'])
        // The recorded failed Node proves this later missing Node was not reached.
        ->and($projected->result['nodes'][2])->toMatchArray(['outcome' => 'skipped', 'reason' => 'not_reached'])
        ->and(array_map(static fn (ReleaseAlert $alert): string => $alert->kind->value, $this->sent))->toBe(['reboot_halted']);

    $body = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
        ->getJson("/api/v1/operations/{$projected->id}")->assertOk()->assertJsonPath('data.result.nodes.0.error_detail', $diagnostic)->json();
    if (! $held) {
        $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $fixture = ['schema' => 1, 'request' => ShowOperationRequest::class,
            'route' => 'GET /api/v1/operations/{operation}', 'status' => 200, 'body' => $body];
        $path = base_path('../../packages/php-sdk/fixtures/operations/operation-show/fleet-reboot-unreachable.json');
        if (getenv('ORBIT_FIXTURES') === 'record') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        expect($fixture)->toBe(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }
})->with([false, true]);

it('marks every Node unknown and halts once when the root has no Node evidence', function (string $status, bool $reportedUnknown): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T02:10:00Z'));
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => $this->swamp->id,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-cut'], 'input' => ['intent' => [
            'order' => [$this->beast->id, $this->prod->id], 'nodes' => [$this->beast->id => ['defer' => 'operator_hold']],
        ], 'task_hosts' => []]]);
    $data = ['run_id' => 'run-cut', 'status' => $status, 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => null, 'failure' => null,
        'result' => $reportedUnknown ? ['nodes' => [['node_id' => $this->beast->id, 'outcome' => 'unknown', 'reason' => 'run_interrupted']]] : []];

    $projected = app(FleetRebootProjection::class)->apply($operation, $data);
    app(FleetRebootProjection::class)->apply($projected, $data);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('fleet.reboot_interrupted')
        ->and($projected->result)->toBe(['nodes' => [
            ['node_id' => $this->beast->id, 'outcome' => 'unknown', 'reason' => 'run_interrupted', 'reboot_hold' => true],
            ['node_id' => $this->prod->id, 'outcome' => 'unknown', 'reason' => 'run_interrupted', 'reboot_hold' => false],
        ], 'error_code' => 'fleet.reboot_interrupted'])
        ->and(array_map(static fn (ReleaseAlert $alert): string => $alert->kind->value, $this->sent))->toBe(['reboot_halted'])
        ->and($this->sent[0]->summary)->toContain('without a complete Node report');

    if ($status === 'interrupted' && ! $reportedUnknown) {
        $body = $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
            ->getJson("/api/v1/operations/{$projected->id}")->assertOk()->assertJsonPath('data.result.nodes.0.outcome', 'unknown')->json();
        $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $fixture = ['schema' => 1, 'request' => ShowOperationRequest::class, 'route' => 'GET /api/v1/operations/{operation}', 'status' => 200, 'body' => $body];
        $path = base_path('../../packages/php-sdk/fixtures/operations/operation-show/fleet-reboot-interrupted.json');
        if (getenv('ORBIT_FIXTURES') === 'record') {
            file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        expect($fixture)->toBe(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }
})->with(['interrupted', 'failed', 'succeeded'])->with([false, true]);

it('uses not_reached only after a recorded halt in a partial Node report', function (): void {
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-partial'], 'input' => ['intent' => [
            'order' => [$this->beast->id, $this->prod->id, $this->vpn->id, $this->swamp->id],
        ], 'task_hosts' => []]]);
    $projected = app(FleetRebootProjection::class)->apply($operation, ['run_id' => 'run-partial', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'operation.native_failed', 'failure' => null,
        'result' => ['nodes' => [
            ['node_id' => $this->beast->id, 'outcome' => 'rebooted'],
            ['node_id' => $this->vpn->id, 'outcome' => 'failed', 'error_code' => 'fleet.reboot_lost'],
        ]]]);

    expect($projected->result['nodes'][1])->toMatchArray(['outcome' => 'unknown', 'reason' => 'run_interrupted'])
        ->and($projected->result['nodes'][3])->toMatchArray(['outcome' => 'skipped', 'reason' => 'not_reached'])
        ->and($projected->result['error_code'])->toBe('fleet.reboot_lost');
});

it('refuses an unreachable diagnostic longer than its public output bound', function (): void {
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'status' => 'running',
        'input' => ['intent' => ['order' => [$this->beast->id]]]]);

    expect(fn () => app(FleetRebootProjection::class)->apply($operation, ['result' => ['nodes' => [[
        'node_id' => $this->beast->id, 'outcome' => 'failed', 'error_code' => 'fleet.node_unreachable', 'error_detail' => str_repeat('x', 4097),
    ]]]]))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.result_invalid'));
});

it('keeps the CI re-run record of a Node: stopped runners, re-run and refused runs, pending runs and errors', function (): void {
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-3'], 'input' => ['intent' => ['order' => [$this->beast->id]], 'task_hosts' => []]]);
    $rerun = ['ci_stopped' => [['name' => 'ci-a', 'frozen_at' => '2026-11-10T03:10:00Z']], 'reran' => [['repository' => 'hardimpactdev/orbit', 'run_id' => 1, 'attempt' => 1, 'jobs' => ['php']]],
        'rerun_refused' => [], 'rerun_pending' => [['repository' => 'hardimpactdev/orbit', 'run_id' => 7]], 'rerun_truncated' => false, 'rerun_error' => 'fleet.reboot_rerun_failed'];

    $projected = app(FleetRebootProjection::class)->apply($operation, ['run_id' => 'run-3', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => null, 'failure' => null, 'result' => ['nodes' => [
            ['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'rebooted', ...$rerun]]]]);

    expect(array_intersect_key($projected->result['nodes'][0], $rerun))->toBe($rerun);
});

it('refuses a result with an unknown outcome', function (): void {
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null,
        'status' => 'running', 'input' => ['intent' => ['order' => [$this->prod->id]]]]);

    expect(fn () => app(FleetRebootProjection::class)->apply($operation, ['status' => 'succeeded', 'result' => ['nodes' => [['node_id' => $this->prod->id, 'outcome' => 'maybe']]]]))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.result_invalid'));
});

it('finds a task host deferred every night overdue, looking past records without a due time', function (): void {
    $since = now()->subDays(4)->startOfSecond()->toIso8601String();
    rebootPass([['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'deferred', 'reason' => 'tasks_busy', 'due_since' => $since]], finished: now()->subDays(3));
    rebootPass([['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'deferred', 'reason' => 'tasks_busy', 'due_since' => $since]], finished: now()->subDays(2));
    rebootPass([['node_id' => $this->beast->id, 'outcome' => 'skipped', 'reason' => 'halted']], finished: now()->subDay());
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null,
        'status' => 'running', 'state' => ['swamp_run_id' => 'run-2'], 'input' => ['intent' => ['order' => [$this->beast->id]], 'task_hosts' => []]]);

    app(FleetRebootProjection::class)->apply($operation, ['run_id' => 'run-2', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => null, 'failure' => null, 'result' => ['nodes' => [
            ['node_id' => $this->beast->id, 'name' => 'beast', 'outcome' => 'deferred', 'reason' => 'tasks_busy', 'due_since' => $since]]]]);

    expect(FleetReboot::dueSince($this->beast->id)?->toIso8601String())->toBe(CarbonImmutable::parse($since)->toIso8601String())
        ->and(array_map(static fn (ReleaseAlert $alert): string => $alert->incident->key($alert->kind), $this->sent))
        ->toBe(['reboot_overdue|fleet-node:'.$this->beast->id.'|due-'.CarbonImmutable::parse($since)->getTimestamp().'|fleet.reboot_overdue']);
});

it('refuses a proof on a task VM host that the pass drains', function (): void {
    $group = Task::query()->create(['project_id' => null, 'title' => 'g', 'brief' => 'b', 'status' => 'running']);
    TaskVm::query()->create(['host_node_id' => $this->beast->id, 'group_id' => $group->id, 'name' => 'beast-tvm-3', 'state' => TaskVmState::Ready,
        'wireguard_ip' => '10.44.0.170', 'provider' => 'incus', 'capacity_pool' => 'task']);
    Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => null, 'status' => 'running',
        'input' => ['task_hosts' => [$this->beast->id]]]);

    expect(fn () => app(ProofVerifyIntent::class)->start($this->gateway, 'firewall:allow', $group->id, 'test', null))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('tasks.host_rebooting'));
});

it('reports a Node due for more than three days to Doctor and alerts once', function (): void {
    $since = now()->subDays(4)->toIso8601String();
    rebootPass([['node_id' => $this->prod->id, 'name' => 'app-prod', 'outcome' => 'rebooted']], finished: now()->subDays(6));
    rebootPass([['node_id' => $this->prod->id, 'name' => 'app-prod', 'outcome' => 'deferred', 'reason' => 'window', 'due_since' => $since]]);

    expect(FleetReboot::dueSince($this->prod->id)?->toIso8601String())->toBe(CarbonImmutable::parse($since)->toIso8601String())
        ->and(FleetReboot::dueSince($this->vpn->id))->toBeNull();
    $report = app(FactDoctor::class)->report([$this->prod->refresh()], [DoctorFamily::Node])->toArray();
    expect(json_encode($report))->toContain('node.reboot_due');

    rebootPass([['node_id' => $this->prod->id, 'name' => 'app-prod', 'outcome' => 'rebooted']]);
    expect(FleetReboot::dueSince($this->prod->id))->toBeNull();
});

it('confirms the swamp Node reboot from its uptime and alerts when no new boot arrives', function (): void {
    $pass = rebootPass([['node_id' => $this->swamp->id, 'name' => 'swamp-fixture', 'outcome' => 'scheduled']], finished: now()->subMinutes(40));
    NodeFact::query()->create(['node_id' => $this->swamp->id, 'kind' => NodeFactKind::Metrics, 'digest' => 'a', 'data' => ['uptime_seconds' => 3600],
        'observed_at' => now(), 'received_at' => now()]);

    new CheckFleetRebootConfirmation()->handle(app(FleetRebootAlerts::class));
    expect(array_map(static fn (ReleaseAlert $alert): string => $alert->incident->key($alert->kind), $this->sent))
        ->toBe(['reboot_unconfirmed|fleet-node:'.$this->swamp->id.'|operation-'.$pass->id.'|fleet.reboot_unconfirmed']);

    NodeFact::query()->where('node_id', $this->swamp->id)->update(['data' => ['uptime_seconds' => 600]]);
    expect(CheckFleetRebootConfirmation::bootedSince($this->swamp->id, CarbonImmutable::parse($pass->finished_at)))->toBeTrue();
});

it('admits a held task host read-only in nightly and selected operator passes and leaves its work unlocked', function (bool $nightly): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    $this->beast->update(['reboot_hold' => true]);
    $group = Task::query()->create(['title' => 'held work', 'brief' => 'b', 'status' => 'running']);
    TaskVm::query()->create(['host_node_id' => $this->beast->id, 'group_id' => $group->id, 'name' => 'held-tvm',
        'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.155', 'provider' => 'incus', 'capacity_pool' => 'task']);
    Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => 'running', 'input' => []]);
    $operation = $nightly ? app(AdmitFleetRebootAction::class)->execute(window: true)
        : Operation::query()->findOrFail($this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
            ->postJson('/api/v1/fleet/reboot', ['node_ids' => [$this->beast->id]])->assertAccepted()->json('data.id'));
    expect($operation->input['intent']['nodes'][(string) $this->beast->id]['defer'])->toBe('operator_hold')
        ->and($operation->input['intent']['order'])->toContain($this->beast->id)
        ->and($operation->input['task_hosts'])->not->toContain($this->beast->id);
    FleetReboot::refuseTaskWork($group);
    $this->beast->update(['reboot_hold' => false]);
    expect($operation->fresh()->input['intent']['nodes'][(string) $this->beast->id]['defer'])->toBe('operator_hold')
        ->and(app(FleetReboot::class)->node($this->beast)['defer'])->toBe('tasks_busy');
})->with(['nightly' => [true], 'operator node_ids' => [false]]);

it('projects the admitted reboot hold for due, not-due and unknown Nodes and keeps held overdue alerts', function (): void {
    $since = now()->subDays(4)->startOfSecond()->toIso8601String();
    $ids = [$this->beast->id, $this->prod->id, $this->vpn->id];
    $operation = Operation::query()->create(['kind' => 'fleet.reboot', 'target_type' => 'fleet', 'target_id' => 1,
        'status' => 'running', 'state' => ['swamp_run_id' => 'held-run'], 'input' => ['intent' => ['order' => $ids,
            'nodes' => array_fill_keys($ids, ['defer' => 'operator_hold'])], 'task_hosts' => []]]);
    $projected = app(FleetRebootProjection::class)->apply($operation, ['run_id' => 'held-run', 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null, 'failure' => null, 'result' => ['nodes' => [
            ['node_id' => $this->beast->id, 'outcome' => 'deferred', 'reason' => 'operator_hold', 'reboot_hold' => true,
                'boot_id_before' => 'held-boot', 'kernel_before' => '7.0.0-42-generic', 'due_since' => $since],
            ['node_id' => $this->prod->id, 'outcome' => 'not_due', 'reboot_hold' => true],
        ]]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
        ->getJson("/api/v1/operations/{$projected->id}")->assertOk()->assertJsonPath('data.result.nodes.0.reboot_hold', true)
        ->assertJsonPath('data.result.nodes.0.reason', 'operator_hold');
    expect(array_column($projected->result['nodes'], 'reboot_hold'))->toBe([true, true, true])
        ->and($projected->result['nodes'][0])->toMatchArray(['reason' => 'operator_hold', 'boot_id_before' => 'held-boot', 'due_since' => $since])
        ->and($projected->result['nodes'][2])->toMatchArray(['outcome' => 'unknown', 'reason' => 'run_interrupted'])
        ->and(FleetReboot::dueSince($this->beast->id)?->getTimestamp())->toBe(CarbonImmutable::parse($since)->getTimestamp())
        ->and(FleetReboot::dueSince($this->prod->id))->toBeNull()
        ->and(array_map(static fn (ReleaseAlert $alert): string => $alert->incident->key($alert->kind), $this->sent))
        ->toBe(['reboot_halted|fleet-node:'.$this->vpn->id.'|operation-'.$projected->id.'|fleet.reboot_interrupted',
            'reboot_overdue|fleet-node:'.$this->beast->id.'|due-'.CarbonImmutable::parse($since)->getTimestamp().'|fleet.reboot_overdue']);
});

it('keeps an idle held task host out of the pass task-work lock', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    $this->beast->update(['reboot_hold' => true]);
    $operation = app(AdmitFleetRebootAction::class)->execute(nodeIds: [$this->beast->id]);
    expect($operation->input['task_hosts'])->toBe([])
        ->and($operation->input['intent']['nodes'][(string) $this->beast->id]['defer'])->toBe('operator_hold');
});
