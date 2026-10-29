<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\TaskVms\GuestBootstrap;
use App\Models\Node;
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
    $agents->malformed = true;
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
