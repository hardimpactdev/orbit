<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationBudgetTable;

/**
 * The swamp role's systemd units, rendered at admission. Convergence applies templates without restarting serve or
 * active workers. The renewal timer then drains and restarts one worker at a time (ADR 0243).
 */
final class SwampRoleUnits
{
    /** Dispatch slots per swamp worker: Swamp's `auto`, one per CPU core (Nick's decision, 2026-11-06). */
    public const string WorkerConcurrency = 'auto';

    /** Scheduled runs serve keeps in flight; one starves overlapping Schedules. */
    public const int ScheduledRuns = 4;

    /** Cold refresh dispatches take about 50 minutes; leave 25 minutes of drain headroom. */
    private const int WorkerStopSeconds = 4500;

    private const int Port = 9418;

    private const string Credentials = '/etc/orbit/swamp-role-worker';

    private const string WorkerHome = '/var/lib/orbit-swamp-worker/%i';

    private const string Checkout = '/apps/orbit-monorepo-staging/checkout/swamp';

    /**
     * @param  string|null  $repository  The `swamp/` folder serve reads; by default the production checkout of the swamp Node.
     * @return array{serve:string,worker:string,renewal_service:string,renewal_timer:string}
     */
    public static function for(string $user, int $nodeId, ?string $repository = null): array
    {
        return ['serve' => self::serve($user, $repository), 'worker' => self::worker($user, $nodeId),
            'renewal_service' => self::renewalService($user, $nodeId), 'renewal_timer' => self::renewalTimer()];
    }

    /**
     * The worker template `orbit-swamp-worker@.service`; each instance reaches serve on loopback. It reads its token files directly, as the
     * account it runs as: systemd credentials do nothing in a system container, so one unit form serves every swamp Node. The folder of a
     * worker is `root:<account>` `0750` and its token files `root:<account>` `0640`.
     */
    private static function worker(string $user, int $nodeId): string
    {
        $home = self::WorkerHome;

        return "[Unit]\nDescription=Orbit swamp role worker %i\nAfter=network-online.target\n"
            ."Wants=network-online.target\nStartLimitIntervalSec=300\nStartLimitBurst=5\n\n"
            ."[Service]\nType=exec\nUser={$user}\nStateDirectory=orbit-swamp-worker/%i\n"
            ."WorkingDirectory={$home}\n"
            ."Environment=HOME={$home} SWAMP_HOME={$home}/home SWAMP_CONFIG_DIR={$home}/config"
            ." SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\n"
            .'ExecStart=/usr/local/bin/swamp worker connect ws://127.0.0.1:'.self::Port.' --token-file='.self::Credentials.'/%i/enrollment.token'
            .' --server-token-file='.self::Credentials."/%i/server.token --cache-dir={$home}/cache"
            .' --concurrency '.self::WorkerConcurrency." --label role=swamp --label node={$nodeId}\n"
            ."Restart=on-failure\nRestartSec=30\nKillMode=mixed\n"
            .'TimeoutStopSec='.self::WorkerStopSeconds."\nUMask=0077\n"
            ."StandardOutput=journal\nStandardError=journal\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }

    private static function renewalService(string $user, int $nodeId): string
    {
        return "[Unit]\nDescription=Renew Orbit swamp worker connections sequentially\n"
            ."After=orbit-swamp-serve.service\nStartLimitIntervalSec=0\n\n"
            ."[Service]\nType=oneshot\n"
            ."ExecStart=/usr/bin/python3 -I /usr/local/lib/orbit-swamp/worker-renewal.py {$user} {$nodeId}\n"
            ."TimeoutStartSec=3h\nRestart=on-failure\nRestartSec=5min\nUMask=0077\n";
    }

    private static function renewalTimer(): string
    {
        return "[Unit]\nDescription=Schedule Orbit swamp worker connection renewal\n\n"
            ."[Timer]\nOnActiveSec=1min\nOnUnitInactiveSec=1h\nAccuracySec=1s\n\n"
            ."[Install]\nWantedBy=timers.target\n";
    }

    /**
     * `orbit-swamp-serve.service`: serve on loopback from the release checkout, draining up to 5 minutes on stop. A run is cut at the
     * longest fixed budget of `OperationBudgetTable`. The flag beats `max-run-duration` in `.swamp/serve.yaml`, which the Gateway
     * rewrites when it publishes triggers.
     */
    private static function serve(string $user, ?string $repository): string
    {
        $home = '/home/'.$user;
        $repo = $repository ?? $home.self::Checkout;
        $config = $home.'/.config/swamp';

        return "[Unit]\nDescription=Orbit swamp role serve\nAfter=network-online.target\nWants=network-online.target\n"
            ."RequiresMountsFor={$repo}\n\n"
            ."[Service]\nType=simple\nUser={$user}\nWorkingDirectory={$repo}\n"
            ."Environment=HOME={$home} PATH={$home}/.local/bin:/usr/local/bin:/usr/bin:/bin"
            ." SWAMP_HOME={$home}/.swamp SWAMP_CONFIG_DIR={$config} SWAMP_API_KEY_FILE={$config}/api-key"
            ." SWAMP_SERVER_TOKEN_FILE={$config}/serve-driver-token SWAMP_REMOTE_ONLY=1 NODE_USE_SYSTEM_CA=1"
            ." SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\n"
            ."ExecStart=/usr/local/bin/swamp serve --repo-dir {$repo} --port ".self::Port.' --host 127.0.0.1'
            ." --auth-mode token --admins user:nckrtl --club-api-key-file {$config}/api-key"
            .' --trusted-hosts swamp.orbit --trust-proxy --dispatch-env-allow=,'
            .' --max-concurrent-scheduled-runs '.self::ScheduledRuns.' --shutdown-drain-timeout 5m'
            .' --max-run-duration '.OperationBudgetTable::longestFixedSeconds()
            ." --remote-only --hot-reload\n"
            ."Restart=on-failure\nRestartSec=2\nTimeoutStopSec=360\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }
}
