<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceState;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\RecordWorkspaceBaseline;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->ssh = new class implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public string $answer = '';

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult(0, $this->answer, '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $this->ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
    $this->node = containerDefaultNode();
    $create = containerCreate(containerDefaultProject(), $this->node);
    $this->default = Instance::query()->findOrFail($create->target_id);
    $this->default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
});

function baselineClone(): Instance
{
    $default = test()->default;
    $instance = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => 'feature',
        'status' => InstanceState::Active, 'branch' => 'feature', 'checkout_path' => '/fixture/reservation', 'source_layout' => 'checkout']);
    $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance)]);
    app(InstanceContainer::class)->reserve($instance, $default->node, $default);

    return $instance->refresh();
}

it('records the baseline once, keeps only hex ids, and never replaces it', function (): void {
    $instance = baselineClone();
    $this->ssh->answer = json_encode(['heads' => [str_repeat('a', 40), '--all', str_repeat('b', 64)], 'stashes' => [str_repeat('c', 40), 'HEAD'],
        'untracked' => ['composer.lock', 7]]);

    new RecordWorkspaceBaseline($instance->id)->handle(app(ContainerWorkspaceRead::class));

    $runtime = $instance->runtime()->firstOrFail();
    expect($runtime->workspace_baseline)->toBe(['heads' => [str_repeat('a', 40), str_repeat('b', 64)], 'stashes' => [str_repeat('c', 40)], 'untracked' => ['composer.lock']])
        ->and($runtime->workspace_baseline_at)->not->toBeNull()
        ->and(array_slice($this->ssh->commands[0]->arguments, 0, 7))->toBe(['timeout', '3', 'python3', '-', 'record', 'orbit-instances', 'instance-'.$instance->id]);

    $this->ssh->answer = json_encode(['heads' => [str_repeat('d', 40)], 'stashes' => [], 'untracked' => []]);
    new RecordWorkspaceBaseline($instance->id)->handle(app(ContainerWorkspaceRead::class));
    expect($instance->runtime()->firstOrFail()->workspace_baseline['heads'])->toBe([str_repeat('a', 40), str_repeat('b', 64)])
        ->and($this->ssh->commands)->toHaveCount(1);
});

it('keeps an untracked baseline above 2000 entries as null and records nothing for an unreadable workspace or a default', function (): void {
    $instance = baselineClone();
    $this->ssh->answer = json_encode(['heads' => [], 'stashes' => [], 'untracked' => null]);
    new RecordWorkspaceBaseline($instance->id)->handle(app(ContainerWorkspaceRead::class));
    expect($instance->runtime()->firstOrFail()->workspace_baseline)->toBe(['heads' => [], 'stashes' => [], 'untracked' => null]);

    $instance->runtime()->update(['workspace_baseline' => null, 'workspace_baseline_at' => null]);
    $this->ssh->answer = json_encode(['state' => 'unknown', 'reason' => 'stopped']);
    new RecordWorkspaceBaseline($instance->id)->handle(app(ContainerWorkspaceRead::class));
    expect($instance->runtime()->firstOrFail()->workspace_baseline)->toBeNull();

    $calls = count($this->ssh->commands);
    new RecordWorkspaceBaseline($this->default->id)->handle(app(ContainerWorkspaceRead::class));
    expect($this->ssh->commands)->toHaveCount($calls)->and($this->default->runtime()->firstOrFail()->workspace_baseline)->toBeNull();
});

it('records nothing when the job starts later than its bound after the create ended', function (): void {
    $instance = baselineClone();
    $this->ssh->answer = json_encode(['heads' => [str_repeat('a', 40)], 'stashes' => [], 'untracked' => []]);
    new RecordWorkspaceBaseline($instance->id, now()->getTimestamp() - RecordWorkspaceBaseline::LateSeconds - 1)->handle(app(ContainerWorkspaceRead::class));
    expect($instance->runtime()->firstOrFail()->workspace_baseline)->toBeNull()->and($this->ssh->commands)->toBe([]);

    new RecordWorkspaceBaseline($instance->id, now()->getTimestamp() - RecordWorkspaceBaseline::LateSeconds)->handle(app(ContainerWorkspaceRead::class));
    expect($instance->runtime()->firstOrFail()->workspace_baseline['heads'])->toBe([str_repeat('a', 40)]);
});

it('runs on the Gateway task queue after the projection commits', function (): void {
    $job = new RecordWorkspaceBaseline(5);
    expect($job->connection)->toBe('gateway')->and($job->queue)->toBe('tasks')->and($job->afterCommit)->toBeTrue();
});
