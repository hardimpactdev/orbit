<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayDiskRetention;

it('uses configured limits and the stable application path for host retention', function (): void {
    config([
        'retention.journal_max_mb' => 128,
        'retention.journal_keep_free_mb' => 2048,
        'retention.journal_days' => 3,
        'retention.journal_runtime_max_mb' => 16,
        'retention.log_rotate_mb' => 8,
        'retention.log_rotate_keep' => 2,
    ]);

    $files = new GatewayDiskRetention()->files('/srv/gateway current/apps/gateway', 'orbit');

    expect($files['/etc/systemd/journald.conf.d/orbit-retention.conf'])->toContain('SystemMaxUse=128M', 'SystemKeepFree=2048M', 'RuntimeMaxUse=16M', 'MaxRetentionSec=3day')
        ->and($files['/etc/logrotate.d/orbit-gateway'])->toContain('"/srv/gateway current/apps/gateway/storage/logs/laravel.log"', 'su orbit orbit', 'maxsize 8M', 'rotate 2', 'copytruncate')
        ->not->toContain('postrotate', 'systemctl', 'laravel-*')
        ->and($files['/etc/systemd/system/logrotate.timer.d/orbit-retention.conf'])->toContain('Persistent=false');
});
