<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseSwampLoad;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Models\Operation;

$source = getcwd();
chdir($source.'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class.": ".$error->getMessage()."\n");
    exit(1);
});
$subscriber = app(SwampRunSubscriber::class);
// Production Horizon/subscriber work the queue concurrently. Drive those same boundaries in this topology.
$loader = new NativeGatewayReleaseSwampLoad(app(App\Actions\Operations\StartOperationAction::class),
    sleep: static function (int $microseconds) use ($subscriber): void {
        $subscriber->recover();
        $subscriber->pass();
        while (($job = app('queue')->connection(GatewayQueue::Connection)->pop(GatewayQueue::Operations->value)) !== null) {
            $job->fire();
        }
        usleep($microseconds);
    });
$sha = trim((string) file_get_contents($source.'/.git/HEAD'));
// The staged source is detached, so HEAD contains its exact release commit.
$begun = microtime(true);
$before = Operation::query()->max('id') ?? 0;
try {
    $loaded = $loader->load($sha);
} catch (App\Domain\GatewayReleases\GatewayReleaseException $error) {
    $operation = Operation::query()->where('kind', 'gateway.swamp_load')->where('id', '>', $before)->latest('id')->firstOrFail();
    $loaded = ['outcome' => 'failed', 'operation_id' => $operation->id, 'error' => $error->getMessage()];
}
$loaded['load_seconds'] = microtime(true) - $begun;
$subscriber->close();
file_put_contents('/home/orbit/.orbit/swamp-proof/gateway-load.json', json_encode($loaded, JSON_THROW_ON_ERROR));
echo json_encode(['bindings' => ['operation' => $loaded['operation_id']]], JSON_THROW_ON_ERROR)."\n";
