<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$path = realpath(__DIR__.'/cli-release.json');
$environment = $root.'/.env';
$content = preg_replace('/^ORBIT_CLI_RELEASE_STATIC_MANIFEST=.*\n/m', '', file_get_contents($environment));
file_put_contents($environment, $content."\nORBIT_CLI_RELEASE_STATIC_MANIFEST=".$path."\n");
Cache::forget('orbit:desired-fleet-state:v1:'.hash('sha256', GatewayVersion::current()));
