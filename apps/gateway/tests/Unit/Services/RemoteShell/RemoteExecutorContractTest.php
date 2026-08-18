<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteHostExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteOrbitGatewayExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\RemoteShell\SshRemoteShell;
use Illuminate\Contracts\Process\InvokedProcess;
use Tests\TestCase;

uses(TestCase::class);

it('keeps run and start on the narrow contracts', function (): void {
    $run = new ReflectionClass(RemoteShell::class)->getMethod('run');
    $start = new ReflectionClass(StartsRemoteShellProcesses::class)->getMethod('start');

    expect((string) $run->getReturnType())
        ->toBe(RemoteShellResult::class)
        ->and((string) $start->getReturnType())
        ->toBe(InvokedProcess::class)
        ->and(remoteExecutorParameterTypes($run))
        ->toBe([Node::class, 'string', 'array'])
        ->and(remoteExecutorParameterTypes($start))
        ->toBe([Node::class, 'string', 'array']);
});

it('keeps start() on the capable executors and withholds it from the local executor', function (): void {
    expect(is_a(RemoteHostExecutor::class, RemoteShell::class, true))
        ->toBeTrue()
        ->and(is_a(RemoteHostExecutor::class, StartsRemoteShellProcesses::class, true))
        ->toBeTrue()
        ->and(is_a(RemoteOrbitGatewayExecutor::class, RemoteShell::class, true))
        ->toBeTrue()
        ->and(is_a(RemoteOrbitGatewayExecutor::class, StartsRemoteShellProcesses::class, true))
        ->toBeTrue()
        ->and(is_a(SshRemoteShell::class, RemoteShell::class, true))
        ->toBeTrue()
        ->and(is_a(SshRemoteShell::class, StartsRemoteShellProcesses::class, true))
        ->toBeTrue()
        ->and(is_a(RemoteLocalExecutor::class, RemoteShell::class, true))
        ->toBeTrue()
        ->and(is_a(RemoteLocalExecutor::class, RunsInternalCommands::class, true))
        ->toBeTrue()
        ->and(is_a(RemoteLocalExecutor::class, StartsRemoteShellProcesses::class, true))
        ->toBeFalse();
});

it('defaults RemoteShell and start resolution to the host executor while keeping runtime explicit', function (): void {
    app()->forgetInstance(RemoteShell::class);
    app()->forgetInstance(StartsRemoteShellProcesses::class);

    expect(app(RemoteShell::class))
        ->toBeInstanceOf(RemoteHostExecutor::class)
        ->and(app(StartsRemoteShellProcesses::class))
        ->toBeInstanceOf(RemoteHostExecutor::class)
        ->and(app(RemoteOrbitGatewayExecutor::class))
        ->toBeInstanceOf(RemoteOrbitGatewayExecutor::class);
});

/**
 * @return list<string>
 */
function remoteExecutorParameterTypes(ReflectionMethod $method): array
{
    return array_map(
        fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $method->getParameters(),
    );
}
