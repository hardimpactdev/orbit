<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseProcessDeclaration;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Models\Node;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => 'active']);
    $node->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->agents = new FakeAgentCommandRunner;
    $this->agents->answer('gateway.inspect-release-hold', ['unrelated_processes' => []]);
    $this->layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway');
    $this->target = str_repeat('a', 40);
    $this->drain = new SystemdGatewayReleaseDrain(
        new GatewayReleaseCommands($this->agents), $this->layout,
        new GatewayReleaseProcessDeclaration($this->agents, $this->layout, app(GatewayServingHost::class)),
    );
    config(['queue.connections.gateway.driver' => 'redis', 'queue.connections.gateway.connection' => 'gateway']);
    $this->redis = Mockery::mock();
    Redis::shouldReceive('connection')->with('gateway')->andReturn($this->redis);
    $this->redis->shouldReceive('zcard')->andReturn(0)->byDefault();
});

it('accepts the native hold and empty reservations on every queue repeatedly', function (): void {
    foreach (['tasks', 'problems', 'task-vms', 'builds', 'builds-optional', 'operations'] as $queue) {
        $this->redis->shouldReceive('zcard')->with('queues:'.$queue.':reserved')->twice()->andReturn(0);
    }

    $this->drain->assertHeld($this->target);
    $this->drain->assertHeld($this->target);

    expect($this->agents->calls)->toBe(array_fill(0, 2, [
        'node' => 'gateway', 'command' => 'gateway.inspect-release-hold',
        'input' => ['checkout' => $this->layout->applicationPath, 'target_sha' => $this->target, 'caller_pid' => getmypid()],
    ]));
});

it('preserves the native hold refusal before reading queue reservations', function (): void {
    $this->agents->refuse('gateway.inspect-release-hold', 'gateway.release_not_drained', ['stage' => 'writer']);
    $this->redis->shouldNotReceive('zcard');

    try {
        $this->drain->assertHeld($this->target);
        $this->fail('Expected a drain refusal.');
    } catch (GatewayReleaseException $error) {
        expect($error->errorCode)->toBe('gateway.release_not_drained')->and($error->step)->toBe('drain');
    }
});

it('refuses missing or malformed declaration collections', function (array $output): void {
    $this->agents->answer('gateway.inspect-release-hold', $output);
    $this->redis->shouldNotReceive('zcard');

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class);
})->with([
    'missing' => [[]], 'null' => [['unrelated_processes' => null]],
    'string' => [['unrelated_processes' => '*']],
    'object' => [['unrelated_processes' => ['process_id' => 1]]],
    'non-object entry' => [['unrelated_processes' => ['*']]],
    'missing id' => [['unrelated_processes' => [[]]]],
    'string id' => [['unrelated_processes' => [['process_id' => '1']]]],
    'zero id' => [['unrelated_processes' => [['process_id' => 0]]]],
]);

it('refuses reservations on every queue without consuming or clearing work', function (string $queue): void {
    $this->redis->shouldReceive('zcard')->with('queues:'.$queue.':reserved')->andReturn(1);

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, "Queue [{$queue}]");
})->with(['tasks', 'problems', 'task-vms', 'builds', 'builds-optional', 'operations']);

it('requires an integer zero reservation observation', function (mixed $reserved): void {
    $this->redis->shouldReceive('zcard')->andReturn($reserved);

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, 'could not be observed');
})->with([null, false, '0', -1]);

it('requires the supported queue configuration', function (string $key): void {
    config(['queue.connections.gateway.'.$key => 'unsupported']);
    $this->redis->shouldNotReceive('zcard');

    expect(fn () => $this->drain->assertHeld($this->target))->toThrow(GatewayReleaseException::class, 'supported Gateway Redis queue');
})->with(['driver', 'connection']);

it('redacts connection errors', function (): void {
    $this->redis->shouldReceive('zcard')->andThrow(new RuntimeException('password=do-not-report'));

    try {
        $this->drain->assertHeld($this->target);
        $this->fail('The observation should refuse.');
    } catch (GatewayReleaseException $error) {
        expect($error->getMessage())->not->toContain('do-not-report')->toContain('could not be read');
    }
});
