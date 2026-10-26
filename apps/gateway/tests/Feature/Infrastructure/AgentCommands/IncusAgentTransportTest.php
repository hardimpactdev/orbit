<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\IncusAgentTransport;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Models\Node;
use Tests\Support\ProcessInput;

final class IncusCarrierFixture implements AgentTransport
{
    public array $calls = [];

    public array $staged = [];

    public bool $failPush = false;

    public function platform(): string
    {
        return 'linux';
    }

    public function binaryPath(): string
    {
        return '/usr/local/bin/orbit-agent';
    }

    public function run(array $arguments, ProtectedInput $envelope, float $timeout, ?Closure $output = null): CommandResult
    {
        $this->calls[] = [$arguments, ProcessInput::contents($envelope), $timeout];

        return new CommandResult($this->failPush && in_array('push', $arguments, true) ? 1 : 0, '{"ok":true,"data":{}}', '', 1, false);
    }

    public function stage(string $localFile, float $timeoutSeconds): string
    {
        $this->staged[] = $localFile;

        return '/var/tmp/host-candidate-'.count($this->staged);
    }
}

it('carries root and account commands with the same protected envelope and deadline', function (bool $root, array $identity): void {
    $host = new IncusCarrierFixture;
    $transport = new IncusAgentTransport($host, 'orbit-tasks', 'tvm-image-check');
    $envelope = '{"agent_sha256":"pin","deadline_ms":4000,"input":{}}';
    $arguments = [...($root ? ['sudo', '-n'] : []), $transport->binaryPath(), 'command', 'agent.status'];

    $result = $transport->run($arguments, ProtectedInput::fromString($envelope), 19.0);

    expect($result->succeeded())->toBeTrue();
    expect($host->calls)->toBe([[['sudo', '-n', '/usr/bin/incus', '--project', 'orbit-tasks', 'exec', '--mode=non-interactive', ...$identity, '--', 'tvm-image-check', ...($root ? [] : ['/usr/sbin/runuser', '--user', 'orbit', '--']), '/usr/local/bin/orbit-agent', 'command', 'agent.status'], $envelope, 19.0]]);
})->with([
    [true, ['--user', '0', '--group', '0', '--env', 'SUDO_USER=orbit']],
    [false, ['--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--env', 'LOGNAME=orbit']],
]);

it('stages distinct candidates and removes only their host copies', function (): void {
    $host = new IncusCarrierFixture;
    $transport = new IncusAgentTransport($host, 'orbit-tasks', 'tvm-image-check');

    $first = $transport->stage('/local/agent', 180.0);
    $second = $transport->stage('/local/agent', 180.0);

    expect($first)->toStartWith('/var/tmp/orbit-agent-candidate-')->not->toBe($second);
    expect($host->staged)->toBe(['/local/agent', '/local/agent']);
    expect($host->calls[0][0])->toBe(['sudo', '-n', '/usr/bin/incus', '--project', 'orbit-tasks', 'file', 'push', '--mode', '0700', '--uid', '0', '--gid', '0', '--', '/var/tmp/host-candidate-1', 'tvm-image-check'.$first]);
    expect($host->calls[1][0])->toBe(['rm', '-f', '--', '/var/tmp/host-candidate-1']);
    expect($host->calls[3][0])->toBe(['rm', '-f', '--', '/var/tmp/host-candidate-2']);
});

it('removes the host candidate even when guest staging fails', function (): void {
    $host = new IncusCarrierFixture;
    $host->failPush = true;

    expect(fn () => new IncusAgentTransport($host, 'orbit-tasks', 'tvm-image-check')->stage('/local/agent', 180.0))->toThrow(function (AgentRunnerFailure $error): void {
        expect($error->kind)->toBe(AgentRunnerFailure::Transport)
            ->and($error->result?->exitCode)->toBe(1);
    });
    expect($host->calls[1][0])->toBe(['rm', '-f', '--', '/var/tmp/host-candidate-1']);
});

it('refuses invalid Incus guest identifiers before creating a transport', function (string $project, string $name): void {
    expect(fn () => AgentGuest::incus(new Node(['architecture' => 'x86_64']), $project, $name))->toThrow(InvalidArgumentException::class);
})->with([['--project', 'vm'], ['p', '../vm'], ['p', 'vm/secret']]);

it('refuses a source Git credential before the host can carry it into a guest', function (): void {
    $host = new IncusCarrierFixture;
    $transport = new IncusAgentTransport($host, 'orbit-tasks', 'tvm-image-check');

    expect(fn () => $transport->run(['/usr/local/bin/orbit-agent', 'command', 'instances.checkout'], ProtectedInput::gitCredential('credential-sentinel'), 30))
        ->toThrow(TaskVmException::class, 'A command that carries a GitHub credential cannot run on a task VM.');
    expect($host->calls)->toBe([])->and($host->staged)->toBe([]);
});
