<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Nodes\LocalAgentRuntimeProbe;

final class AgentRuntimeProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:agent-runtime:probe {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe the local Orbit agent runtime user and CLI access';

    public function handle(LocalAgentRuntimeProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:agent-runtime:probe')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess($probe->check());
    }
}
