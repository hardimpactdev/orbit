<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use Illuminate\Support\Facades\Config;

/** Stock host retention, installed with the Gateway release units without restarting services. */
final readonly class GatewayDiskRetention
{
    /** @return array<string, string> */
    public function files(string $applicationPath, string $user): array
    {
        $journalMax = Config::integer('retention.journal_max_mb');
        $journalFree = Config::integer('retention.journal_keep_free_mb');
        $journalDays = Config::integer('retention.journal_days');
        $runtimeMax = Config::integer('retention.journal_runtime_max_mb');
        $logMax = Config::integer('retention.log_rotate_mb');
        $logKeep = Config::integer('retention.log_rotate_keep');
        $logPath = addcslashes(rtrim($applicationPath, '/').'/storage/logs/laravel.log', '\\"');

        return [
            '/etc/systemd/journald.conf.d/orbit-retention.conf' => <<<CONF
                # Managed by Orbit.
                [Journal]
                SystemMaxUse={$journalMax}M
                SystemKeepFree={$journalFree}M
                RuntimeMaxUse={$runtimeMax}M
                MaxRetentionSec={$journalDays}day

                CONF,
            '/etc/logrotate.d/orbit-gateway' => <<<CONF
                # Managed by Orbit. Daily Laravel logs rotate through Laravel itself.
                "{$logPath}" {
                    su {$user} {$user}
                    daily
                    maxsize {$logMax}M
                    rotate {$logKeep}
                    missingok
                    notifempty
                    compress
                    copytruncate
                }

                CONF,
            '/etc/systemd/system/logrotate.timer.d/orbit-retention.conf' => <<<'CONF'
                # Managed by Orbit.
                [Timer]
                OnCalendar=
                OnCalendar=hourly
                AccuracySec=1m
                RandomizedDelaySec=0
                Persistent=false

                CONF,
        ];
    }
}
