<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;

/** The CI role's fixed host actions, through the shared remote-program transport. */
final readonly class CiRunnerHostProgram
{
    public function __construct(
        private RemoteProgramRunner $runner,
        private RemoteProgramCatalog $catalog,
    ) {}

    /** @param 'converge'|'inspect'|'remove'|'purge' $action */
    public function run(Node $node, string $action): ProgramResult
    {
        return $this->runner->run($node, $this->catalog->program('ci-runners.host', user: 'root'), ['action' => $action]);
    }
}
