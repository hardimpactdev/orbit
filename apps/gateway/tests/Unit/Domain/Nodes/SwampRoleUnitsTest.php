<?php

declare(strict_types=1);

use App\Domain\Nodes\SwampRoleUnits;

// The exact text the Python runtime wrote on Node 209, so the first cfgmgmt converge there changes no unit.
it('renders serve exactly as it runs on the swamp Node, with its scheduled run limit and drain', function (): void {
    expect(SwampRoleUnits::for('orbit', 209)['serve'])->toBe(<<<'UNIT_TEXT'
[Unit]
Description=Orbit swamp role serve
After=network-online.target
Wants=network-online.target
RequiresMountsFor=/home/orbit/apps/orbit-monorepo-staging/checkout/swamp

[Service]
Type=simple
User=orbit
WorkingDirectory=/home/orbit/apps/orbit-monorepo-staging/checkout/swamp
Environment=HOME=/home/orbit PATH=/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin SWAMP_HOME=/home/orbit/.swamp SWAMP_CONFIG_DIR=/home/orbit/.config/swamp SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key SWAMP_SERVER_TOKEN_FILE=/home/orbit/.config/swamp/serve-driver-token SWAMP_REMOTE_ONLY=1 NODE_USE_SYSTEM_CA=1 SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1
ExecStart=/home/orbit/.local/bin/swamp serve --repo-dir /home/orbit/apps/orbit-monorepo-staging/checkout/swamp --port 9418 --host 127.0.0.1 --auth-mode token --admins user:nckrtl --club-api-key-file /home/orbit/.config/swamp/api-key --trusted-hosts swamp.orbit --trust-proxy --dispatch-env-allow=, --max-concurrent-scheduled-runs 4 --shutdown-drain-timeout 5m --remote-only --hot-reload
Restart=on-failure
RestartSec=2
TimeoutStopSec=360

[Install]
WantedBy=multi-user.target
UNIT_TEXT."\n");
});

it('renders the worker template exactly, with its loopback serve, tokens, slots and labels', function (): void {
    expect(SwampRoleUnits::for('orbit', 209)['worker'])->toBe(<<<'UNIT_TEXT'
[Unit]
Description=Orbit swamp role worker %i
After=network-online.target
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
Type=exec
User=orbit
StateDirectory=orbit-swamp-worker/%i
WorkingDirectory=/var/lib/orbit-swamp-worker/%i
Environment=HOME=/var/lib/orbit-swamp-worker/%i SWAMP_HOME=/var/lib/orbit-swamp-worker/%i/home SWAMP_CONFIG_DIR=/var/lib/orbit-swamp-worker/%i/config SWAMP_NO_UPDATE_CHECK=1 SWAMP_NO_TELEMETRY=1
LoadCredential=enrollment:/etc/orbit/swamp-role-worker/%i/enrollment.token
LoadCredential=server:/etc/orbit/swamp-role-worker/%i/server.token
ExecStart=/usr/local/bin/swamp worker connect ws://127.0.0.1:9418 --token-file=%d/enrollment --server-token-file=%d/server --cache-dir=/var/lib/orbit-swamp-worker/%i/cache --concurrency 8 --label role=swamp --label node=209
Restart=on-failure
RestartSec=15
KillMode=mixed
TimeoutStopSec=infinity
UMask=0077
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT_TEXT."\n");
});
