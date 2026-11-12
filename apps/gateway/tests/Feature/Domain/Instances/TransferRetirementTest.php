<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceTransferAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\InstanceDestroyWorktrees;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

        public function run(Node $node, RemoteProgram $program, array $input = [], ?GitReadEnvironment $gitRead = null, ?float $timeoutSeconds = null, ?Closure $cancelled = null): ProgramResult
        {
            $this->calls++;
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
    $project = containerDefaultProject();
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'owner', 'checkout_path' => $this->root.'/owner', 'status' => 'active', 'root' => '.', 'source_layout' => 'checkout']);
    $this->dependent = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->source->id,
        'name' => 'moved', 'checkout_path' => $this->root.'/moved', 'status' => 'active', 'root' => '.', 'source_layout' => 'checkout']);
    $this->transfer = InstanceTransfer::query()->create(['instance_id' => $this->instance->id, 'source_node_id' => $this->source->id,
        'destination_node_id' => $this->destination->id, 'destination_name' => 'owner', 'destination_path' => '/destination/owner',
        'destination_domain' => 'owner.fixture.test', 'source_layout' => 'checkout', 'source_path' => '/fixture/owner',
        'source_route_id' => 1, 'status' => 'reserved', 'current_step' => 'reserved']);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

it('refuses a moved dependent before transfer reservation or any serving change', function (): void {
    $this->transfer->delete();
    ($this->git)(['-C', $this->root.'/owner', 'worktree', 'add', '-q', '--detach', $this->root.'/old']);
    rename($this->root.'/old', $this->root.'/moved');
    $before = $this->instance->fresh()->getAttributes();
    try {
        app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
        test()->fail('A dependent was admitted');
    } catch (ResourceOperationException $failure) {
        expect($failure->errorCode)->toBe('instance.worktree_dependents')->and($failure->status)->toBe(409)
            ->and($failure->details['dependents'][0]['id'])->toBe($this->dependent->id);
    }
    expect($this->instance->fresh()->getAttributes())->toBe($before)->and(InstanceTransfer::query()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0);
    ($this->git)(['-C', $this->root.'/moved', 'status', '--porcelain']);
});

it('does not read Git again for an already admitted transfer', function (): void {
    ($this->git)(['-C', $this->root.'/owner', 'worktree', 'add', '-q', '--detach', $this->root.'/moved']);
    try {
        app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    } catch (ResourceOperationException $failure) {
        // This minimal admitted fixture has no stored environment; later intent validation still applies.
        expect($failure->errorCode)->not->toBe('instance.worktree_dependents');
    }
    expect($this->reader->calls)->toBe(0);
});

it('retains admission warnings in the terminal transfer Operation and on replay', function (): void {
    $admission = app(InstanceDestroyWorktrees::class)->assertAvailable($this->instance);
    $warnings = $admission['checkout_warnings'];
    expect($warnings[0]['instance_ids'])->toBe([$this->dependent->id]);
    $this->instance->update(['node_id' => $this->destination->id, 'checkout_path' => '/destination/owner']);
    $this->transfer->update(['cutover_at' => now(), 'current_step' => 'cutover']);
    $operation = Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->source->id, 'status' => 'running', 'input' => ['transfer_id' => $this->transfer->id, 'route_transfer' => [], 'checkout_warnings' => $warnings],
        'state' => ['swamp_run_id' => 'fixture-transfer'], 'started_at' => now()]);
    $terminal = ['run_id' => 'fixture-transfer', 'status' => 'failed', 'error_code' => 'operation.native_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['forward' => ['ready' => true]]];
    $projection = app(NativeInstanceTransferProjection::class);
    $projected = $projection->apply($operation, $terminal);
    expect($projected->result['warnings'])->toBe($warnings)->and($projected->result['cutover'])->toBeTrue()
        ->and($this->instance->fresh()->node_id)->toBe($this->destination->id);
    expect($projection->apply($projected, $terminal)->result)->toBe($projected->result);
});
