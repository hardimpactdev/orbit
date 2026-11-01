#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificateIssuer;
use Illuminate\Contracts\Console\Kernel;

try {
    if ($argc !== 1) {
        throw new RuntimeException;
    }
    require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
    $app = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $app->make(GatewayCertificateIssuer::class)->issue('gateway.orbit', '10.44.0.1');
    echo "{\"certificate\":true}\n";
} catch (Throwable) {
    fwrite(STDERR, "e2e.fixture_gateway_certificate_failed\n");
    exit(1);
}
