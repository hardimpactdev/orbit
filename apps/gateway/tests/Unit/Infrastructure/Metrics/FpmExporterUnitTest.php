<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;

it('starts the FPM exporter after the WireGuard tunnel and restarts it after a clean exit', function (): void {
    $unit = (new ServiceMetricsConfigRenderer)->unit();

    expect($unit)->toContain("After=network-online.target wg-quick@orbit.service\n", "Restart=always\nRestartSec=5\n")
        ->not->toContain('Restart=on-failure');
});
