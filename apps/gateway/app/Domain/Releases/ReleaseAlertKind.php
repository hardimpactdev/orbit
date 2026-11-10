<?php

declare(strict_types=1);

namespace App\Domain\Releases;

enum ReleaseAlertKind: string
{
    case ReleaseFailed = 'release_failed';
    case ReleasePaused = 'release_paused';
    case ReleaseStalled = 'release_stalled';
    case ReleaseCleanupPaused = 'release_cleanup_paused';
    case ReleaseSchedulerSilent = 'release_scheduler_silent';
    case ReleaseGatewayAgentFailed = 'release_gateway_agent_failed';
    case RolloutHalted = 'rollout_halted';
    case RebootHalted = 'reboot_halted';
    case RebootUnconfirmed = 'reboot_unconfirmed';
    case RebootOverdue = 'reboot_overdue';
    case InstanceDeployFailed = 'instance_deploy_failed';
    case InstanceDeployStalled = 'instance_deploy_stalled';
    case InstanceDeployPaused = 'instance_deploy_paused';

    public function label(): string
    {
        return match ($this) {
            self::ReleaseFailed => 'Release failed',
            self::ReleasePaused => 'Release paused',
            self::ReleaseStalled => 'Release stalled',
            self::ReleaseCleanupPaused => 'Document cleanup paused after release',
            self::ReleaseSchedulerSilent => 'Scheduler silent after release',
            self::ReleaseGatewayAgentFailed => 'Gateway agent update failed after release',
            self::RolloutHalted => 'Rollout halted',
            self::RebootHalted => 'Fleet reboot halted',
            self::RebootUnconfirmed => 'Fleet reboot unconfirmed',
            self::RebootOverdue => 'Node reboot overdue',
            self::InstanceDeployFailed => 'Automatic deployment failed',
            self::InstanceDeployStalled => 'Automatic deployment stalled',
            self::InstanceDeployPaused => 'Automatic deployment paused',
        };
    }
}
