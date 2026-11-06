<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationBudgetTable;

/**
 * The swamp role's systemd units, rendered at admission. Official cfgmgmt steps write them on the Node and never
 * restart serve or a running worker, so a changed unit takes effect at its next restart (ADR 0243).
 */
final class SwampRoleUnits
{
    /** Dispatch slots per swamp worker, about twice the swamp Node's cores. */
    public const int WorkerConcurrency = 8;

    /** Scheduled runs serve keeps in flight; one starves overlapping Schedules. */
    public const int ScheduledRuns = 4;

    /** The drain a worker gets on stop before systemd kills it: the value of Swamp's own worker daemon unit. */
    private const int WorkerStopSeconds = 300;

    private const int Port = 9418;

    private const string Credentials = '/etc/orbit/swamp-role-worker';

    private const string WorkerHome = '/var/lib/orbit-swamp-worker/%i';

    private const string Checkout = '/apps/orbit-monorepo-staging/checkout/swamp';

    /**
     * @param  string|null  $repository  The `swamp/` folder serve reads; by default the production checkout of the swamp Node.
     * @return array{serve:string,worker:string}
     */
    public static function for(string $user, int $nodeId, ?string $repository = null): array
    {
        return ['serve' => self::serve($user, $repository), 'worker' => self::worker($user, $nodeId)];
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
            ."Wants=network-online.target\nStartLimitIntervalSec=0\n\n"
            ."[Service]\nType=exec\nUser={$user}\nStateDirectory=orbit-swamp-worker/%i\n"
            ."WorkingDirectory={$home}\n"
            ."Environment=HOME={$home} SWAMP_HOME={$home}/home SWAMP_CONFIG_DIR={$home}/config"
            ." SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\n"
            .'ExecStart=/usr/local/bin/swamp worker connect ws://127.0.0.1:'.self::Port.' --token-file='.self::Credentials.'/%i/enrollment.token'
            .' --server-token-file='.self::Credentials."/%i/server.token --cache-dir={$home}/cache"
            .' --concurrency '.self::WorkerConcurrency." --label role=swamp --label node={$nodeId}\n"
            ."Restart=on-failure\nRestartSec=15\nKillMode=mixed\n"
            .'TimeoutStopSec='.self::WorkerStopSeconds."\nUMask=0077\n"
            ."StandardOutput=journal\nStandardError=journal\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
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
