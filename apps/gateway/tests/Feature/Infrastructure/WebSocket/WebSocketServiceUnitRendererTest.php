<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;

it('starts Reverb only once the Gateway API answers, with a bounded wait that never holds the unit', function (): void {
    $unit = (new WebSocketServiceUnitRenderer)->render('/home/orbit/reverb', 'orbit', 'orbit', 8080);

    expect($unit)->toContain("After=network-online.target\nWants=network-online.target\n", "TimeoutStartSec=180\n")
        ->and($unit)->toContain("ExecStartPre=/bin/sh -c 'for i in \$\$(seq 1 120); do curl -sk -m 1 -o /dev/null https://gateway.orbit/up && exit 0; sleep 0.25; done; exit 0'\n")
        ->and(strpos($unit, 'ExecStartPre='))->toBeLessThan(strpos($unit, 'ExecStart=/usr/bin/php'))
        ->and($unit)->toContain('ExecStart=/usr/bin/php /home/orbit/reverb/artisan orbit:reverb:start --host=127.0.0.1 --port=8080');
});
