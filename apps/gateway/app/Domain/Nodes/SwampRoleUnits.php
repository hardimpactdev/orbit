<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

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

    /** The worker template `orbit-swamp-worker@.service`; each instance reaches serve on loopback. */
    private static function worker(string $user, int $nodeId): string
    {
        $home = self::WorkerHome;

        return "[Unit]\nDescription=Orbit swamp role worker %i\nAfter=network-online.target\n"
            ."Wants=network-online.target\nStartLimitIntervalSec=0\n\n"
            ."[Service]\nType=exec\nUser={$user}\nStateDirectory=orbit-swamp-worker/%i\n"
            ."WorkingDirectory={$home}\n"
            ."Environment=HOME={$home} SWAMP_HOME={$home}/home SWAMP_CONFIG_DIR={$home}/config"
            ." SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1\n"
            .'LoadCredential=enrollment:'.self::Credentials."/%i/enrollment.token\n"
            .'LoadCredential=server:'.self::Credentials."/%i/server.token\n"
            .'ExecStart=/usr/local/bin/swamp worker connect ws://127.0.0.1:'.self::Port.' --token-file=%d/enrollment'
            ." --server-token-file=%d/server --cache-dir={$home}/cache"
            .' --concurrency '.self::WorkerConcurrency." --label role=swamp --label node={$nodeId}\n"
            ."Restart=on-failure\nRestartSec=15\nKillMode=mixed\nTimeoutStopSec=infinity\nUMask=0077\n"
            ."StandardOutput=journal\nStandardError=journal\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }

    /** `orbit-swamp-serve.service`: serve on loopback from the release checkout, draining up to 5 minutes on stop. */
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
            ." --remote-only --hot-reload\n"
            ."Restart=on-failure\nRestartSec=2\nTimeoutStopSec=360\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }
}
