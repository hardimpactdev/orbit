<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->agents = new FakeAgentCommandRunner;
    $this->commands = new GatewayReleaseCommands($this->agents);
    $this->layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway', $this->commands);
    $this->runtime = new ArtisanGatewayReleaseRuntime($this->layout, $this->commands, '/home/orbit/.orbit/gateway-release-step.lock');
});

it('sends each phase to the prepared release and keeps its control-plane report', function (): void {
    $serve = ['caddy' => 'unchanged', 'fpm' => 'unchanged', 'opcache' => ['outcome' => 'reset', 'cache_full' => false]];
    $this->agents->answer('gateway.handoff-release', ['succeeded' => true, 'report' => $serve]);
    expect($this->runtime->handoff('0123456789ab'))->toBe($serve);
    $schedule = ['scheduler' => 'restarted', 'scheduler_drain' => ['outcome' => 'drained'], 'cleanup_paused' => false];
    $this->agents->answer('gateway.handoff-release', ['succeeded' => true, 'report' => $schedule]);
    expect($this->runtime->schedule('0123456789ab'))->toBe($schedule);
    expect($this->agents->calls)->toBe(array_map(fn (string $phase) => [
        'node' => 'gateway', 'command' => 'gateway.handoff-release',
        'input' => ['checkout' => $this->layout->applicationPath, 'release' => '0123456789ab', 'phase' => $phase, 'step_lock' => '/home/orbit/.orbit/gateway-release-step.lock'],
    ], ['serve', 'schedule']));
});

it('keeps cleanup paused unless the phase explicitly resumes it', function (mixed $value, bool $paused): void {
    $this->agents->answer('gateway.handoff-release', ['succeeded' => true, 'report' => ['scheduler' => 'restarted', 'cleanup_paused' => $value]]);
    expect($this->runtime->schedule('0123456789ab')['cleanup_paused'])->toBe($paused);
})->with([[null, true], [true, true], [false, false], ['false', false]]);

it('preserves the handoff domain error reported by the selected release', function (): void {
    $this->agents->answer('gateway.handoff-release', ['succeeded' => false, 'report' => [
        'error_code' => 'gateway.release_scheduler_busy', 'message' => 'A tick held the lock.',
    ]]);
    expect(fn () => $this->runtime->schedule('0123456789ab'))->toThrow(
        fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_scheduler_busy')
            ->and($e->step)->toBe('handoff')->and($e->status)->toBe(500)->and($e->getMessage())->toBe('A tick held the lock.'),
    );
});

it('refuses malformed or incomplete phase results', function (array $result): void {
    $this->agents->answer('gateway.handoff-release', $result);
    expect(fn () => $this->runtime->handoff('0123456789ab'))->toThrow(
        fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_handoff_failed')->and($e->status)->toBe(500),
    );
    expect($this->agents->calls)->toHaveCount(1);
})->with([
    [[]],
    [['succeeded' => 'true', 'report' => ['caddy' => 'unchanged']]],
    [['succeeded' => true, 'report' => 'invalid']],
    [['succeeded' => true, 'report' => ['caddy']]],
    [['succeeded' => true, 'report' => []]],
    [['succeeded' => false, 'report' => ['caddy' => 'unchanged']]],
]);

it('does not use the deployer handoff when the target release cannot run it', function (): void {
    $this->agents->refuse('gateway.handoff-release', 'gateway.release_handoff_failed');
    expect(fn () => $this->runtime->handoff('0123456789ab'))->toThrow(
        fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_handoff_failed')->and($e->step)->toBe('handoff')->and($e->status)->toBe(500),
    );
    expect($this->agents->calls)->toHaveCount(1);
});
