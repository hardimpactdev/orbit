<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Models\Node;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeHostKeyScanner;
use Tests\Support\RecordingProcessRunner;
use Tests\Support\SshGuardedProcessRunner;
use Tests\Support\UnreachableSshExecutor;

it('fakes SSH execution by default in Feature tests', function (): void {
    $ssh = app(SshExecutor::class);
    expect($ssh)->toBeInstanceOf(UnreachableSshExecutor::class);
    $node = new Node(['name' => 'fake-node', 'wireguard_ip' => '10.44.0.91', 'user' => 'orbit']);
    $command = new RemoteCommand(['git', 'rev-parse', 'HEAD']);

    expect(fn () => app(DevelopmentSshExecutor::class)->execute($node, $command, 'task-workspace-state', 'tasks.diff_failed'))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('tasks.diff_failed')
                ->and($exception->result?->exitCode)->toBe(255);
        });

    expect($ssh->commands)->toBe([$command])
        ->and($ssh->connections[0]->host)->toBe('10.44.0.91')
        ->and($ssh->connections[0]->user)->toBe('orbit');
});

it('fakes host key scans by default without reaching the host or its jump host', function (bool $jump): void {
    $scanner = app(HostKeyScanner::class);
    expect($scanner)->toBeInstanceOf(FakeHostKeyScanner::class);
    $via = $jump ? new Node(['name' => 'jump', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']) : null;

    expect(fn () => $scanner->scan('192.0.2.91', 2222, $via))
        ->toThrow(SshHostKeyScanException::class, 'Feature tests fake host key scans');

    expect($scanner->calls)->toBe([['host' => '192.0.2.91', 'port' => 2222, 'via' => $via]]);
})->with(['direct' => false, 'jump host' => true]);

it('blocks a native SSH process and reports the attempt even if its exception is swallowed', function (string $program): void {
    $runner = new RecordingProcessRunner;
    $guard = new SshGuardedProcessRunner($runner);

    expect(fn () => $guard->run(new ProcessInvocation([$program, '--', '192.0.2.91'])))
        ->toThrow(LogicException::class, 'Feature tests cannot start real ssh or ssh-keyscan processes');

    expect($runner->ran)->toBe([]);
    expect(fn () => $guard->assertNoStrayProcesses())->toThrow(AssertionFailedError::class);
})->with(['ssh', 'ssh-keyscan', '/usr/bin/ssh', '/usr/bin/ssh-keyscan']);

it('keeps ordinary native processes available in Feature tests', function (): void {
    expect(app(NativeProcessRunner::class))->toBeInstanceOf(SshGuardedProcessRunner::class);

    $result = app(ProcessRunner::class)->run(new ProcessInvocation([PHP_BINARY, '-r', 'echo "local";']));

    expect($result->exitCode)->toBe(0)->and($result->stdout)->toBe('local');
});

it('allows tests to opt into real SSH implementations with a recording process runner', function (): void {
    $runner = new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;

            return new CommandResult(255, '', 'recorded transport failure', 0, false);
        }
    };
    app()->instance(ProcessRunner::class, $runner);
    app()->bind(SshExecutor::class, NativeSshExecutor::class);

    $result = app(SshExecutor::class)->execute(
        new SshConnection('192.0.2.91', 'orbit', 22, '/keys/id', '/keys/known_hosts', shareConnection: false),
        new RemoteCommand(['true']),
    );
    expect($result->stderr)->toBe('recorded transport failure')
        ->and($runner->calls)->toHaveCount(1)
        ->and($runner->calls[0]->arguments[0])->toBe('ssh');
});
