<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Models\Node;
use Closure;
use LogicException;

/** Tests must replace remote effects explicitly; the default checkout fixture has no dependents. */
final class FakeRemoteProgramRunner implements RemoteProgramRunner
{
    public function run(Node $node, RemoteProgram $program, array $input = [], ?GitReadEnvironment $gitRead = null, ?float $timeoutSeconds = null, ?Closure $cancelled = null): ProgramResult
    {
        if ($program->name === 'instance.destroy-git' && ($input['action'] ?? null) === 'inspect') {
            return new ProgramResult('inspected', ['dependents' => []], '');
        }

        throw new LogicException('Bind an explicit RemoteProgramRunner fake for this test.');
    }
}
