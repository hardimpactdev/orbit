<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetServingRelease;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->agents = new FakeAgentCommandRunner;
    $this->commands = new GatewayReleaseCommands($this->agents);
    $this->layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway', $this->commands);
});

it('sends release identity and force intent and records the native source', function (): void {
    $sha = str_repeat('a', 40);
    $this->agents->answer('gateway.switch-release', ['previous' => 'bbbbbbbbbbbb'])
        ->answer('gateway.check-release-forward', ['source' => 'aaaaaaaaaaaa', 'source_sha' => $sha]);
    $switcher = new GatewayReleaseSwitcher($this->layout, $this->commands);
    $guard = new GatewayReleaseGuard($this->layout, $this->commands);

    expect($switcher->switchTo('aaaaaaaaaaaa'))->toBe('bbbbbbbbbbbb');
    expect($guard->source())->toBe(['source' => 'aaaaaaaaaaaa', 'source_sha' => $sha]);
    $guard->assertForward($sha, true);
    expect($this->agents->calls)->toBe([
        ['node' => 'gateway', 'command' => 'gateway.switch-release', 'input' => ['checkout' => $this->layout->applicationPath, 'release' => 'aaaaaaaaaaaa']],
        ['node' => 'gateway', 'command' => 'gateway.check-release-forward', 'input' => ['checkout' => $this->layout->applicationPath, 'sha' => null, 'force' => false]],
        ['node' => 'gateway', 'command' => 'gateway.check-release-forward', 'input' => ['checkout' => $this->layout->applicationPath, 'sha' => $sha, 'force' => true]],
    ]);
});

it('keeps GitHub repository policy and branch configuration in the Gateway', function (): void {
    $this->agents->answer('gateway.inspect-release-source', ['origin' => 'git@github.com:hardimpactdev/orbit.git']);
    $source = new GatewayReleaseSource($this->commands, 'main', 'Required checks', $this->layout);

    expect($source->repositoryName())->toBe('hardimpactdev/orbit');
    expect($this->agents->calls)->toBe([['node' => 'gateway', 'command' => 'gateway.inspect-release-source', 'input' => ['checkout' => $this->layout->applicationPath]]]);
    $this->agents->answer('gateway.inspect-release-source', ['origin' => 'https://gitlab.com/owner/repo']);
    expect($source->repositoryName())->toBeNull();
    $this->agents->refuse('gateway.inspect-release-source', 'gateway.release_source_unknown');
    expect($source->repositoryName())->toBeNull();
});

it('preserves native refusals with the release phase and HTTP status', function (string $command, string $code, string $step, int $status): void {
    $sha = str_repeat('a', 40);
    $this->agents->refuse($command, $code);
    try {
        match ($command) {
            'gateway.switch-release' => $this->commands->switch($this->layout, 'aaaaaaaaaaaa'),
            'gateway.check-release-forward' => $this->commands->forward($this->layout, $sha, false),
        };
        $this->fail('Expected a native refusal.');
    } catch (GatewayReleaseException $e) {
        expect($e->errorCode)->toBe($code)->and($e->step)->toBe($step)->and($e->status)->toBe($status);
    }
})->with([
    ['gateway.switch-release', 'gateway.release_not_prepared', 'switch', 422],
    ['gateway.switch-release', 'gateway.release_not_adopted', 'switch', 409],
    ['gateway.switch-release', 'gateway.release_path_conflict', 'switch', 409],
    ['gateway.switch-release', 'gateway.release_switch_failed', 'switch', 500],
    ['gateway.check-release-forward', 'gateway.release_downgrade', 'guard', 409],
]);

it('rejects malformed output without reporting a completed operation', function (): void {
    $this->agents->answerMalformed('gateway.switch-release', [])
        ->answerMalformed('gateway.check-release-forward', ['source' => null, 'source_sha' => str_repeat('a', 40)])
        ->answerMalformed('gateway.inspect-release-source', ['origin' => false]);
    expect(fn () => $this->commands->switch($this->layout, 'aaaaaaaaaaaa'))->toThrow(GatewayReleaseException::class, 'invalid release command result');
    expect(fn () => $this->commands->forward($this->layout, null, false))->toThrow(GatewayReleaseException::class, 'invalid release command result');
    expect(fn () => $this->commands->origin($this->layout))->toThrow(GatewayReleaseException::class, 'invalid release command result');
});

it('refuses without an active Gateway Node before sending a command', function (): void {
    $this->node->roles()->delete();
    expect(fn () => $this->commands->switch($this->layout, 'aaaaaaaaaaaa'))->toThrow(GatewayReleaseException::class, 'No active Gateway Node');
    expect($this->agents->calls)->toBe([]);
});

arch('release selection uses the command boundary')->expect([
    GatewayReleaseCommands::class, GatewayReleaseSwitcher::class, GatewayReleaseGuard::class, GatewayReleaseSource::class,
    GatewayReleaseLayout::class,
    FleetServingRelease::class,
    GatewayReleasePromoter::class,
])->not->toUse([ProcessRunner::class, SshExecutor::class, 'file_get_contents', 'file_exists', 'filemtime', 'is_readable', 'is_link', 'symlink', 'readlink', 'realpath', 'unlink']);

it('reads release observations through the runner and keeps their native ordering', function (): void {
    $this->agents->answer('gateway.inspect-releases', [
        'current' => 'aaaaaaaaaaaa', 'state' => 'adopted', 'superseded' => false,
        'commits' => ['aaaaaaaaaaaa' => str_repeat('a', 40), 'bbbbbbbbbbbb' => str_repeat('b', 40)],
        'incomplete' => ['cccccccccccc' => 123],
        'retained' => ['bbbbbbbbbbbb', 'aaaaaaaaaaaa'],
    ]);

    expect($this->layout->currentReleaseId())->toBe('aaaaaaaaaaaa');
    expect($this->layout->preparedCommit('aaaaaaaaaaaa'))->toBe(str_repeat('a', 40));
    expect($this->layout->preparedCommit('cccccccccccc'))->toBeNull();
    expect($this->layout->retainedReleaseIds())->toBe(['bbbbbbbbbbbb', 'aaaaaaaaaaaa']);
    expect($this->layout->incompleteReleaseIds())->toBe(['cccccccccccc']);
    expect($this->agents->calls[0])->toBe(['node' => 'gateway', 'command' => 'gateway.inspect-releases', 'input' => ['checkout' => $this->layout->applicationPath, 'running_application' => null]]);

    $this->agents->answer('gateway.inspect-releases', ['current' => null, 'state' => 'unreadable', 'superseded' => false, 'commits' => [], 'incomplete' => [], 'retained' => []]);
    expect($this->layout->isAdopted())->toBeFalse();
});

it('rejects malformed release inventories', function (array $changes): void {
    $this->agents->answerMalformed('gateway.inspect-releases', array_replace([
        'current' => null, 'state' => 'unreadable', 'superseded' => false, 'commits' => [], 'incomplete' => [], 'retained' => [],
    ], $changes));

    expect(fn () => $this->layout->observe())->toThrow(GatewayReleaseException::class, 'invalid release inventory');
})->with([
    'bad current' => [['current' => '../other']],
    'mismatched revision' => [['commits' => ['aaaaaaaaaaaa' => str_repeat('b', 40)], 'retained' => ['aaaaaaaaaaaa']]],
    'missing retained revision' => [['retained' => ['aaaaaaaaaaaa']]],
    'bad incomplete time' => [['incomplete' => ['aaaaaaaaaaaa' => '123']]],
    'prepared marked incomplete' => [['commits' => ['aaaaaaaaaaaa' => str_repeat('a', 40)], 'incomplete' => ['aaaaaaaaaaaa' => 123], 'retained' => ['aaaaaaaaaaaa']]],
]);

it('constructs paths without resolving an observer or an active Gateway', function (): void {
    $this->node->roles()->delete();
    $layout = new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway');

    expect($layout->releaseApplicationPath('aaaaaaaaaaaa'))->toBe('/home/orbit/releases/aaaaaaaaaaaa/apps/gateway');
    expect($this->agents->calls)->toBe([]);
});

it('uses native serving identity and stops rollout when it cannot be observed', function (): void {
    config(['orbit.gateway_checkout' => $this->layout->applicationPath]);
    app()->instance(GatewayReleaseObserver::class, $this->commands);
    $serving = new FleetServingRelease('/home/orbit/releases/bbbbbbbbbbbb/apps/gateway');
    $this->agents->answer('gateway.inspect-releases', [
        'current' => 'aaaaaaaaaaaa', 'state' => 'adopted', 'superseded' => true,
        'commits' => ['aaaaaaaaaaaa' => str_repeat('a', 40)], 'incomplete' => [], 'retained' => ['aaaaaaaaaaaa'],
    ]);

    expect($serving->supersedes())->toBeTrue();
    expect($serving->read())->toBe(['state' => 'adopted', 'commit' => str_repeat('a', 40)]);
    expect($this->agents->calls[0]['input']['running_application'])->toBe('/home/orbit/releases/bbbbbbbbbbbb/apps/gateway');

    $this->agents->refuse('gateway.inspect-releases', 'gateway.release_path_conflict');
    expect($serving->read())->toBe(['state' => 'unreadable', 'commit' => null]);
    expect($serving->supersedes())->toBeFalse();
});

it('reports a lost switch result as a failure and never resends it', function (): void {
    $this->agents->answer('gateway.switch-release', static fn (): array => throw new AgentRunnerFailure('gateway.switch-release', 'missing_result', 'Lost connection'));

    try {
        $this->commands->switch($this->layout, 'aaaaaaaaaaaa');
        $this->fail('Expected the lost result to fail.');
    } catch (GatewayReleaseException $e) {
        expect($e->errorCode)->toBe('gateway.release_switch_failed')->and($e->step)->toBe('switch')->and($e->status)->toBe(500);
    }
    expect($this->agents->calls)->toHaveCount(1);
});

it('rejects malformed native preparation results', function (string $command, array $result): void {
    $this->agents->answerMalformed($command, $result);
    $invoke = match ($command) {
        'gateway.inspect-release-preparation' => fn () => $this->commands->inspectPreparation($this->layout, str_repeat('a', 40), 0, 0),
        'gateway.prepare-release' => fn () => $this->commands->prepare($this->layout, str_repeat('a', 40), 0, 0, null, '/home/orbit/.orbit/gateway-release-step.lock'),
        'gateway.finish-release' => fn () => $this->commands->finish($this->layout, str_repeat('a', 40), '/home/orbit/.orbit/gateway-release-step.lock'),
    };

    expect($invoke)->toThrow(GatewayReleaseException::class, 'invalid release command result');
    expect($this->agents->calls)->toHaveCount(1);
})->with([
    'missing fields' => ['gateway.inspect-release-preparation', []],
    'missing origin and sha' => ['gateway.inspect-release-preparation', ['sha' => null, 'origin' => null]],
    'unexpected credential request' => ['gateway.inspect-release-preparation', ['sha' => str_repeat('a', 40), 'origin' => 'https://github.com/owner/repo']],
    'wrong local commit' => ['gateway.inspect-release-preparation', ['sha' => str_repeat('b', 40), 'origin' => null]],
    'invalid reuse flag' => ['gateway.prepare-release', ['sha' => str_repeat('a', 40), 'reused' => 1]],
    'wrong prepared commit' => ['gateway.prepare-release', ['sha' => str_repeat('b', 40), 'reused' => false]],
    'invalid completion flag' => ['gateway.finish-release', ['changed' => null]],
]);
