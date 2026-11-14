<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;

it('starts Reverb after the .orbit DNS routing and once the Gateway API answers, with a stock curl retry that never holds the unit', function (): void {
    $unit = (new WebSocketServiceUnitRenderer)->render('/home/orbit/reverb', 'orbit', 'orbit', 8080);

    expect($unit)->toContain("After=network-online.target wg-quick@orbit.service orbit-gateway-dns.service\nWants=network-online.target\n", "TimeoutStartSec=180\n")
        ->and($unit)->toContain("ExecStartPre=-/usr/bin/curl -sk -o /dev/null -m 2 --retry 30 --retry-delay 1 --retry-connrefused --retry-all-errors https://gateway.orbit/up\n")
        ->and(strpos($unit, 'ExecStartPre='))->toBeLessThan(strpos($unit, 'ExecStart=/usr/bin/php'));
});
