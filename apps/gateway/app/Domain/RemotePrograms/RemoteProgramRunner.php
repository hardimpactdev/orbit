<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

use App\Domain\GitHub\GitReadEnvironment;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Models\Node;
use Closure;
use Illuminate\Container\Attributes\Bind;
use SensitiveParameter;

/**
 * Runs a remote program on a Node and returns its declared outcome. Callers never see the transport.
 */
#[Bind(SshProgramRunner::class)]
interface RemoteProgramRunner
{
    /**
     * @param  array<string, mixed>|list<string>  $input  A JSON object for a Python program, the positional arguments of a Bash program.
     * @param  GitReadEnvironment|null  $gitRead  Reaches the program as `git_read`. Input with a credential travels protected.
     * @param  float|null  $timeoutSeconds  At most the program's own timeout.
     * @param  (Closure(): bool)|null  $cancelled  Stops the run when it returns true.
     *
     * @throws RemoteProgramFailed
     */
    public function run(
        Node $node,
        RemoteProgram $program,
        array $input = [],
        #[SensitiveParameter] ?GitReadEnvironment $gitRead = null,
        ?float $timeoutSeconds = null,
        ?Closure $cancelled = null,
    ): ProgramResult;
}
