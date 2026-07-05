<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Nodes\LocalAgentAclEnsure;
use RuntimeException;

final class AgentAclEnsureCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:agent-acl:ensure {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Ensure local ACL access for the Orbit agent runtime user';

    public function handle(LocalAgentAclEnsure $acl): int
    {
        if (! $this->verifyOperationToken('internal:agent-acl:ensure')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($acl->ensure());
        } catch (RuntimeException $exception) {
            return $this->renderFailure('agent_acl_failed', $exception->getMessage(), []);
        }
    }
}
