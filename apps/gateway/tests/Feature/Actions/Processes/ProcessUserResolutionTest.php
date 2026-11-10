<?php

declare(strict_types=1);

use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Actions\Processes\CreateProcessAction;
use App\Actions\Processes\UpdateProcessAction;
use App\Data\Processes\AddProcessData;
use App\Data\Processes\UpdateProcessData;
use App\Domain\AgentView\AgentStateView;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function processUserNode(bool $withFact = true): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'apps', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.5',
        'public_ssh_host' => '10.44.0.5', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint',
    ]));
    $view = new CacheAgentStateView(Cache::store('array'));
    app()->instance(AgentStateView::class, $view);
    $view->putSubscriber(true, true, 1);
    $view->putNode($node->id, [], 'absent', 1, CacheAgentStateView::now(), null);
    if ($withFact) {
        app(RecordNodeFactAction::class)->execute($node, NodeFactKind::Accounts, '', CarbonImmutable::now()->subMonth(), [
            'accounts' => [['user' => 'app', 'uid' => 1001, 'group' => 'app', 'home' => '/srv/app', 'shell' => '/bin/bash']],
        ]);
    }

    return $node;
}

function processUserData(Node $node, ?string $directory = null): AddProcessData
{
    return new AddProcessData(ProcessTargetType::Node, $node->id, 'worker', ProcessRuntime::Systemd,
        ['/usr/bin/sleep', '60'], null, $directory, [], [], [], 'never', false, user: 'app');
}

function processUserExisting(Node $node): Process
{
    return Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'worker', 'runtime' => 'systemd',
        'working_directory' => '/home/orbit', 'runtime_config' => ['command' => ['/usr/bin/sleep', '60']],
        'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'active',
    ]);
}

beforeEach(function (): void {
    $this->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
});

it('creates a named-user Process with its observed home or explicit working directory', function (?string $directory, string $expected): void {
    $node = processUserNode();

    $operation = app(CreateProcessAction::class)->admit(processUserData($node, $directory));
    $process = Process::query()->sole();

    expect($operation->kind)->toBe('process.create')
        ->and($operation->target_id)->toBe($process->id);
    expect($process->working_directory)->toBe($expected)
        ->and($process->runtime_config['user'])->toBe('app');
})->with(['observed home' => [null, '/srv/app'], 'explicit directory' => ['/srv/worker', '/srv/worker']]);

it('updates a Process to a named user with its observed home or explicit working directory', function (?string $directory, string $expected): void {
    $node = processUserNode();
    $process = processUserExisting($node);

    $operation = app(UpdateProcessAction::class)->admit($process,
        new UpdateProcessData(processUserData($node, $directory), ['user', 'working_directory']));
    $process->refresh();

    expect($operation->kind)->toBe('process.update')
        ->and($operation->target_id)->toBe($process->id);
    expect($process->working_directory)->toBe($expected)
        ->and($process->runtime_config['user'])->toBe('app');
})->with(['observed home' => [null, '/srv/app'], 'explicit directory' => ['/srv/worker', '/srv/worker']]);

it('refuses create without an accounts fact before recording intent', function (): void {
    $node = processUserNode(false);

    expect(fn () => app(CreateProcessAction::class)->admit(processUserData($node)))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.user_unavailable');
        });
    expect(Process::query()->count())->toBe(0);
    expect(Operation::query()->count())->toBe(0);
});

it('refuses update without an accounts fact and preserves the existing Process', function (): void {
    $node = processUserNode(false);
    $process = processUserExisting($node);

    expect(fn () => app(UpdateProcessAction::class)->admit($process,
        new UpdateProcessData(processUserData($node), ['user', 'working_directory'])))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('process.user_unavailable');
        });
    expect($process->refresh()->working_directory)->toBe('/home/orbit')
        ->and($process->runtime_config)->toBe(['command' => ['/usr/bin/sleep', '60']])
        ->and($process->status->value)->toBe('active');
    expect(Operation::query()->count())->toBe(0);
});
