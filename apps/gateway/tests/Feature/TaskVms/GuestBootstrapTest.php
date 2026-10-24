<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\TaskVms\GuestBootstrap;
use App\Models\Node;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGuestAgentCommandRunner;

function bootstrapGuest(): AgentGuest
{
    return AgentGuest::incus(new Node(['architecture' => 'x86_64']), 'orbit-tasks', 'builder');
}

it('requests typed readiness and fingerprint observations', function (): void {
    $agents = new FakeGuestAgentCommandRunner;
    $pin = 'SHA256:'.str_repeat('a', 43);
    $agents->answer = static fn (AgentGuest $guest, string $command): array => $command === 'guest.fingerprint' ? ['fingerprint' => $pin] : ['ready' => false];
    $bootstrap = new GuestBootstrap($agents);
    $guest = bootstrapGuest();

    expect($bootstrap->ready($guest, 'builder'))->toBeFalse()
        ->and($bootstrap->fingerprint($guest, 'builder'))->toBe($pin)
        ->and(array_column($agents->calls, 'command'))->toBe(['guest.cloud-init', 'guest.fingerprint'])
        ->and($agents->calls[0]['guest'])->toBe($guest);
});

it('rejects missing or mistyped observation fields', function (string $method, array $answer): void {
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn (): array => $answer;

    expect(fn () => new GuestBootstrap($agents)->{$method}(bootstrapGuest(), 'builder'))->toThrow(function (TaskVmException $error): void {
        expect($error->errorCode)->toBe('task_vm.invalid_host_output');
    });
})->with([['ready', []], ['ready', ['ready' => 'true']], ['fingerprint', []], ['fingerprint', ['fingerprint' => 123]]]);

it('maps guest refusals to public VM failures', function (string $code, array $details, string $message): void {
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn () => throw new AgentCommandRefused('guest.cloud-init', $code, 'refused', $details);

    expect(fn () => new GuestBootstrap($agents)->ready(bootstrapGuest(), 'builder'))->toThrow(function (TaskVmException $error) use ($code, $message): void {
        expect($error->errorCode)->toBe($code)->and($error->getMessage())->toContain($message);
    });
})->with([
    ['task_vm.bootstrap_failed', ['errors' => '["apt failed"]'], 'apt failed'],
    ['task_vm.invalid_host_output', ['reason' => 'missing status'], 'missing status'],
    ['task_vm.host_command_failed', ['stderr' => 'key unavailable'], 'key unavailable'],
]);

it('installs once before polling readiness', function (): void {
    $this->freezeTime();
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn (): array => ['ready' => count($agents->calls) === 2];
    $guest = bootstrapGuest();

    new GuestBootstrap($agents)->awaitReady($guest, 'builder', 30);

    expect($agents->installs)->toBe([$guest])->and($agents->calls)->toHaveCount(2);
    Sleep::assertSleptTimes(1);
});

it('retries unavailable staging before the first observation', function (): void {
    $this->freezeTime();
    $agents = new FakeGuestAgentCommandRunner;
    $agents->installAnswer = static function () use ($agents): bool {
        if (count($agents->installs) === 1) {
            throw new AgentRunnerFailure('agent.install', AgentRunnerFailure::Transport, 'unavailable', result: new CommandResult(255, '', 'connection refused', 1, false));
        }

        return true;
    };

    new GuestBootstrap($agents)->awaitReady(bootstrapGuest(), 'builder', 30);

    expect($agents->installs)->toHaveCount(2)->and($agents->calls)->toHaveCount(1);
    Sleep::assertSleptTimes(1);
});

it('treats only blank failed transport results as pending', function (string $kind, ?CommandResult $result, bool $pending): void {
    $agents = new FakeGuestAgentCommandRunner;
    $failure = new AgentRunnerFailure('guest.cloud-init', $kind, 'failure', result: $result);
    $agents->answer = static fn () => throw $failure;
    $call = fn () => new GuestBootstrap($agents)->ready(bootstrapGuest(), 'builder');

    if ($pending) {
        expect($call())->toBeFalse();
    } else {
        expect($call)->toThrow($failure);
    }
})->with([
    [AgentRunnerFailure::Transport, new CommandResult(255, '', 'offline', 1, false), true],
    [AgentRunnerFailure::Transport, new CommandResult(1, 'broken json', '', 1, false), false],
    [AgentRunnerFailure::Transport, new CommandResult(1, '', '', 1, true), false],
    [AgentRunnerFailure::Transport, new CommandResult(0, '', '', 1, false), false],
    [AgentRunnerFailure::Transport, null, false],
    [AgentRunnerFailure::Timeout, null, false],
    [AgentRunnerFailure::MalformedResult, null, false],
]);

it('does not retry installation refusal or configuration failure', function (bool $refusal): void {
    $agents = new FakeGuestAgentCommandRunner;
    $error = $refusal ? new AgentCommandRefused('agent.install', 'agent.install_failed', 'failed') : new RuntimeException('invalid manifest');
    $agents->installAnswer = static fn () => throw $error;

    expect(fn () => new GuestBootstrap($agents)->awaitReady(bootstrapGuest(), 'builder', 30))->toThrow($error);
    expect($agents->installs)->toHaveCount(1)->and($agents->calls)->toBe([]);
    Sleep::assertSleptTimes(0);
})->with([true, false]);

it('stops polling at the boot deadline', function (): void {
    $this->freezeTime();
    $start = now()->getTimestamp();
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn (): array => ['ready' => false];

    expect(fn () => new GuestBootstrap($agents)->awaitReady(bootstrapGuest(), 'builder', 4))->toThrow(function (TaskVmException $error): void {
        expect($error->errorCode)->toBe('task_vm.bootstrap_timeout');
    });
    expect(now()->getTimestamp() - $start)->toBe(4);
    expect($agents->calls)->toHaveCount(2)->and($agents->installs)->toHaveCount(1);
});

it('waits for the guest system bus before installing on first boot', function (): void {
    $this->freezeTime();
    $agents = new FakeGuestAgentCommandRunner;
    $agents->installAnswer = static function () use ($agents): bool {
        if (count($agents->installs) === 1) {
            throw new AgentCommandRefused('agent.install', 'agent.supervisor_not_ready', 'The system bus is not available.');
        }

        return true;
    };

    new GuestBootstrap($agents)->awaitReady(bootstrapGuest(), 'builder', 30);

    expect($agents->installs)->toHaveCount(2)->and($agents->calls)->toHaveCount(1);
    Sleep::assertSleptTimes(1);
});
