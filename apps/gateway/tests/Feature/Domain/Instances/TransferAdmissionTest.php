<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceTransferAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStore;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $control = Node::withoutEvents(fn () => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->root = sys_get_temp_dir().'/orbit-transfer-admission-'.bin2hex(random_bytes(8));
    mkdir($this->root);
    $this->git = function (array $args): void {
        new Process(['git', ...$args])->mustRun();
    };
    ($this->git)(['init', '-q', $this->root.'/owner']);
    ($this->git)(['-C', $this->root.'/owner', '-c', 'user.name=Fixture', '-c', 'user.email=f@example.test', 'commit', '--allow-empty', '-qm', 'fixture']);
    $this->reader = new class implements RemoteProgramRunner
    {
        public int $calls = 0;

        public ?RemoteProgramFailure $failure = null;

        public function run(Node $node, RemoteProgram $program, array $input = [], ?GitReadEnvironment $gitRead = null, ?float $timeoutSeconds = null, ?Closure $cancelled = null): ProgramResult
        {
            $this->calls++;
            if ($this->failure !== null) {
                throw new RemoteProgramFailed($program->name, $this->failure);
            }
            $process = new Process(['python3', '-c', $program->source]);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $value = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            return new ProgramResult($value['outcome'], $value['data'], '');
        }
    };
    app()->instance(RemoteProgramRunner::class, $this->reader);
    $this->source = containerDefaultNode();
    $this->destination = containerDefaultNode('destination', '10.44.0.8');
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'fixture.test', 'state' => 'active']);
    $this->source->update(['cluster_id' => $cluster->id]);
    $this->destination->update(['cluster_id' => $cluster->id]);
    $this->source->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $this->source->refresh();
    $this->destination->refresh();
    file_put_contents($this->root.'/agent.json', json_encode(['commit' => str_repeat('a', 40), 'version' => '0.0.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->root.'/agent.json', $this->root.'/cache'));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $this->socket = new class implements WebSocketClient
    {
        public array $messages = [];

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->messages[] = $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            return [['id' => $this->last['id'], 'type' => $this->last['type'], 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs', 'secretKey' => $this->last['payload']['key'] ?? '', 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'fixture-token']);
    $project = containerDefaultProject();
    $project->update(['root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'owner', 'checkout_path' => $this->root.'/owner', 'status' => 'active', 'root' => 'public', 'source_layout' => 'checkout', 'source_is_laravel' => false, 'provisioning_step' => 'active', 'branch' => 'main']);
    $this->dependent = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'moved', 'checkout_path' => $this->root.'/moved', 'status' => 'active', 'root' => 'public', 'source_layout' => 'checkout']);
    ($this->git)(['clone', '-q', $this->root.'/owner', $this->root.'/moved']);
    $this->instance->environmentValues()->create(['env_key' => 'APP_NAME', 'env_value' => 'fixture']);
    $this->route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'owner.fixture.test', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);

});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

/** @return array<string,mixed> */
function transferAdmissionRecords(): array
{
    $result = [];
    foreach (['instances', 'instance_transfers', 'routes', 'route_targets', 'processes', 'schedules', 'vite_port_assignments', 'ssr_port_assignments', 'operations'] as $table) {
        $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
    }

    return $result;
}

it('admits a clean host transfer through the full admission pipeline', function (): void {
    $operation = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    expect($operation->kind)->toBe('instance.transfer')->and($operation->input['checkout_warnings'])->toBe([])
        ->and($this->reader->calls)->toBe(1)->and(InstanceTransfer::query()->count())->toBe(1);
});

it('refuses a moved dependent before any serving or reservation write', function (): void {
    new Filesystem()->deleteDirectory($this->root.'/moved');
    ($this->git)(['-C', $this->root.'/owner', 'worktree', 'add', '-q', '--detach', $this->root.'/old']);
    rename($this->root.'/old', $this->root.'/moved');
    App\Models\Process::query()->create(['owner_type' => 'instance', 'owner_id' => $this->instance->id, 'name' => 'worker',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/true']], 'desired_state' => 'running', 'status' => 'active']);
    Schedule::query()->create(['target_type' => 'instance', 'target_id' => $this->instance->id, 'host_node_id' => $this->source->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'swamp', 'swamp_workflow_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']);
    DB::table('vite_port_assignments')->insert(['instance_id' => $this->dependent->id, 'node_id' => $this->destination->id, 'port' => 6201]);
    $before = transferAdmissionRecords();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql, $match)) {
            $writes[] = $match[1];
        }
    });
    try {
        app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
        test()->fail('A dependent was admitted');
    } catch (ResourceOperationException $failure) {
        expect($failure->errorCode)->toBe('instance.worktree_dependents')->and($failure->status)->toBe(409)
            ->and($failure->details['dependents'][0]['id'])->toBe($this->dependent->id);
    }
    expect(transferAdmissionRecords())->toBe($before)->and($writes)->toBe([])->and($this->socket->messages)->toBe([]);
    ($this->git)(['-C', $this->root.'/moved', 'status', '--porcelain']);
});

it('admits an unreadable candidate with named warnings from the real admission wiring', function (): void {
    $marker = $this->root.'/moved/.git';
    chmod($marker, 0);
    try {
        $operation = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
        expect($operation->input['checkout_warnings'])->toBe([['code' => 'instance.checkout_unknown', 'path' => $this->root.'/moved', 'cause' => 'unreadable', 'instance_ids' => [$this->dependent->id]]]);
    } finally {
        chmod($marker, 0o700);
    }
});

it('refuses a container source without reading the Node', function (): void {
    app(InstanceContainer::class)->reserve($this->instance, $this->source);
    $before = transferAdmissionRecords();
    try {
        app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
        test()->fail('Container transfer admitted');
    } catch (ResourceOperationException $failure) {
        expect($failure->errorCode)->toBe('operation.kind_unavailable');
    }
    expect($this->reader->calls)->toBe(0)->and(transferAdmissionRecords())->toBe($before);
});

it('keeps a Node read failure distinct and leaves all records unchanged', function (RemoteProgramFailure $cause): void {
    $this->reader->failure = $cause;
    $before = transferAdmissionRecords();
    try {
        app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
        test()->fail('Unreadable Node admitted');
    } catch (ResourceOperationException $failure) {
        expect($failure->errorCode)->toBe('instance.host_unavailable')->and($failure->status)->toBe(409)->and($failure->details['reason'])->toBe($cause->value);
    }
    expect(transferAdmissionRecords())->toBe($before)->and($this->socket->messages)->toBe([]);
})->with([RemoteProgramFailure::Unreachable, RemoteProgramFailure::TimedOut]);

/** Project a real admitted transfer through its normal terminal boundary. */
function transferAdmissionTerminal(Operation $operation, bool $succeeded): Operation
{
    app(OperationStore::class)->change($operation, ['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-transfer'], 'started_at' => now()]);

    return app(NativeInstanceTransferProjection::class)->apply($operation,
        ['run_id' => 'fixture-transfer', 'status' => $succeeded ? 'succeeded' : 'failed', 'error_code' => $succeeded ? null : 'operation.native_failed',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['forward' => ['ready' => $succeeded]]]);
}

it('successfully retries an admitted open transfer without another Node read', function (): void {
    $first = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    InstanceTransfer::query()->findOrFail($first->input['transfer_id'])->update(['current_step' => 'source-captured']);
    transferAdmissionTerminal($first, false);
    $this->reader->failure = RemoteProgramFailure::Unreachable;
    $retry = app(StartInstanceTransferAction::class)->execute($this->instance->fresh(), new TransferInstanceData($this->destination->id, null, null));
    expect($retry->kind)->toBe('instance.transfer')->and($retry->id)->not->toBe($first->id)
        ->and($retry->input['transfer_id'])->toBe($first->input['transfer_id'])->and($this->reader->calls)->toBe(1);
});

it('successfully returns a completed matching transfer without another Node read', function (): void {
    $first = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    $completed = transferAdmissionTerminal($first, true);
    $this->reader->failure = RemoteProgramFailure::Unreachable;
    $before = transferAdmissionRecords();
    $retry = app(StartInstanceTransferAction::class)->execute($this->instance->fresh(), new TransferInstanceData($this->destination->id, null, null));
    expect(transferAdmissionRecords())->toBe($before);
    expect($retry->id)->toBe($completed->id)->and($retry->status->value)->toBe('succeeded')->and($this->reader->calls)->toBe(1);
});

it('reads again for a genuinely new attempt after a closed failure', function (): void {
    $first = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    transferAdmissionTerminal($first, false);
    $retry = app(StartInstanceTransferAction::class)->execute($this->instance->fresh(), new TransferInstanceData($this->destination->id, null, null));
    expect($retry->input['transfer_id'])->not->toBe($first->input['transfer_id'])->and($this->reader->calls)->toBe(2);
});

it('projects warnings from an admitted transfer and preserves them on terminal replay', function (): void {
    chmod($this->root.'/moved/.git', 0);
    try {
        $operation = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    } finally {
        chmod($this->root.'/moved/.git', 0o700);
    }
    $projected = transferAdmissionTerminal($operation, true);
    expect($projected->result['warnings'])->toBe($operation->input['checkout_warnings']);
    $terminal = ['run_id' => 'fixture-transfer', 'status' => 'succeeded', 'error_code' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['forward' => ['ready' => true]]];
    expect(app(NativeInstanceTransferProjection::class)->apply($projected, $terminal)->result)->toBe($projected->result);
});
