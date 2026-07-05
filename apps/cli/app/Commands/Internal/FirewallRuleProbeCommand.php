<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Firewall\LocalFirewallRuleProbe;
use App\Services\Firewall\LocalFirewallRuleProbeFailure;

final class FirewallRuleProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:firewall-rule:probe {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe local firewall backend rules for internal drift checks';

    public function handle(LocalFirewallRuleProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:firewall-rule:probe')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($probe->check());
        } catch (LocalFirewallRuleProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
