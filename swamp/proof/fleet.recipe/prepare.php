<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$revision = trim(file_get_contents(dirname($root, 2).'/.git/HEAD'));
if (preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1) {
    throw new RuntimeException('The proof checkout must have a detached commit.');
}
file_put_contents(dirname($root, 2).'/REVISION', $revision."\n");
$path = realpath(__DIR__.'/cli-release.json');
$environment = $root.'/.env';
$content = preg_replace('/^ORBIT_CLI_RELEASE_STATIC_MANIFEST=.*\n/m', '', file_get_contents($environment));
file_put_contents($environment, $content."\nORBIT_CLI_RELEASE_STATIC_MANIFEST=".$path."\n");
Cache::forget('orbit:desired-fleet-state:v1:'.hash('sha256', GatewayVersion::current()));
