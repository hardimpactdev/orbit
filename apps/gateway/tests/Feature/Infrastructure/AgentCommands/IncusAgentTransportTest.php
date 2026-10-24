<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\IncusAgentTransport;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Models\Node;

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

    public function run(array $arguments, ProtectedInput $envelope, float $timeout): CommandResult
    {
        $this->calls[] = [$arguments, stream_get_contents($envelope->stream()), $timeout];

        return new CommandResult($this->failPush && in_array('push', $arguments, true) ? 1 : 0, '{"ok":true,"data":{}}', '', 1, false);
    }

    public function stage(string $localFile): string
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
    [true, ['--user', '0', '--group', '0']],
    [false, ['--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'USER=orbit', '--env', 'LOGNAME=orbit']],
]);

it('stages distinct candidates and removes only their host copies', function (): void {
    $host = new IncusCarrierFixture;
    $transport = new IncusAgentTransport($host, 'orbit-tasks', 'tvm-image-check');

    $first = $transport->stage('/local/agent');
    $second = $transport->stage('/local/agent');

    expect($first)->toStartWith('/var/tmp/orbit-agent-candidate-')->not->toBe($second);
    expect($host->staged)->toBe(['/local/agent', '/local/agent']);
    expect($host->calls[0][0])->toBe(['sudo', '-n', '/usr/bin/incus', '--project', 'orbit-tasks', 'file', 'push', '--mode', '0700', '--uid', '0', '--gid', '0', '--', '/var/tmp/host-candidate-1', 'tvm-image-check'.$first]);
    expect($host->calls[1][0])->toBe(['rm', '-f', '--', '/var/tmp/host-candidate-1']);
    expect($host->calls[3][0])->toBe(['rm', '-f', '--', '/var/tmp/host-candidate-2']);
});

it('removes the host candidate even when guest staging fails', function (): void {
    $host = new IncusCarrierFixture;
    $host->failPush = true;

    expect(fn () => new IncusAgentTransport($host, 'orbit-tasks', 'tvm-image-check')->stage('/local/agent'))->toThrow(RuntimeException::class, 'could not be staged');
    expect($host->calls[1][0])->toBe(['rm', '-f', '--', '/var/tmp/host-candidate-1']);
});

it('refuses invalid Incus guest identifiers before creating a transport', function (string $project, string $name): void {
    expect(fn () => AgentGuest::incus(new Node(['architecture' => 'x86_64']), $project, $name))->toThrow(InvalidArgumentException::class);
})->with([['--project', 'vm'], ['p', '../vm'], ['p', 'vm/secret']]);
