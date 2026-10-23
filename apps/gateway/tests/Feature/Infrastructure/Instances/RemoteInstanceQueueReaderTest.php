<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Instances\RemoteInstanceQueueReader;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeAgentCommandRunner;

function queue_reader_horizon(): Process
{
    $node = Node::query()->create(['name' => 'queue-node', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20']);

    return Process::query()->create([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'horizon', 'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/srv/shop', 'runtime_config' => ['command' => ['/usr/bin/php8.4', 'artisan', 'horizon']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
}

it('reads the queue report through the agent with the Process PHP and directory', function (): void {
    $agents = (new FakeAgentCommandRunner)->answer('instances.read-queue', ['report' => ['installed' => false]]);
    app()->instance(AgentCommandRunner::class, $agents);

    $report = app(RemoteInstanceQueueReader::class)->read(queue_reader_horizon(), 'failed', 10);

    expect($report)->toBe(['installed' => false])
        ->and($agents->calls[0]['input'])->toBe(['user' => 'orbit', 'directory' => '/srv/shop', 'php' => '/usr/bin/php8.4', 'state' => 'failed', 'limit' => 10]);
});

it('fails the read when the agent cannot report the queue', function (): void {
    app()->instance(AgentCommandRunner::class, (new FakeAgentCommandRunner)->refuse('instances.read-queue', 'instances.queue_failed'));

    expect(fn () => app(RemoteInstanceQueueReader::class)->read(queue_reader_horizon(), 'pending', 5))
        ->toThrow(fn (ResourceOperationException $exception) => expect([$exception->errorCode, $exception->status])->toBe(['instance.queue_failed', 502]));
});
