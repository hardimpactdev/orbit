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
    case RolloutStalled = 'rollout_stalled';
    case RolloutCaddySkipped = 'rollout_caddy_skipped';
    case RolloutCliFallback = 'rollout_cli_fallback';
    case InstanceDeployFailed = 'instance_deploy_failed';
    case InstanceDeployStalled = 'instance_deploy_stalled';
    case InstanceSmokeHeld = 'instance_smoke_held';
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
            self::RolloutStalled => 'Rollout stalled',
            self::RolloutCaddySkipped => 'Rollout kept a live Caddyfile',
            self::RolloutCliFallback => 'Rollout used an older CLI release',
            self::InstanceDeployFailed => 'Automatic deployment failed',
            self::InstanceDeployStalled => 'Automatic deployment stalled',
            self::InstanceSmokeHeld => 'Production smoke held',
            self::InstanceDeployPaused => 'Automatic deployment paused',
        };
    }
}
