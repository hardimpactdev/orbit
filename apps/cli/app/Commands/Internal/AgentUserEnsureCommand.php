<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Nodes\LocalAgentUserEnsure;
use RuntimeException;

final class AgentUserEnsureCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:agent-user:ensure {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Ensure the local Orbit agent runtime user exists and is locked';

    public function handle(LocalAgentUserEnsure $agentUser): int
    {
        if (! $this->verifyOperationToken('internal:agent-user:ensure')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($agentUser->ensure());
        } catch (RuntimeException $exception) {
            return $this->renderFailure('agent_user_failed', $exception->getMessage(), []);
        }
    }
}
