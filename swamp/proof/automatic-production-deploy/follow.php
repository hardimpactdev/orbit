<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Deployment\AutomaticDeployFireProjection;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Models\Setting;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$subscriber = app(SwampRunSubscriber::class);
$deadline = microtime(true) + 55;
try {
    do {
        $subscriber->pass();
        while (($job = app('queue')->connection(GatewayQueue::Connection)->pop(GatewayQueue::Operations->value)) !== null) {
            $job->fire();
        }
        $state = json_decode(Setting::query()->where('key', AutomaticDeployFireProjection::StateKey)->value('value') ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        if (in_array($argv[1], $state['runs'] ?? [], true)) {
            echo json_encode(['projected' => $argv[1]]), PHP_EOL;
            exit(0);
        }
        usleep(200_000);
    } while (microtime(true) < $deadline);
} finally {
    $subscriber->close();
}
throw new RuntimeException('The shared subscriber did not record the native policy fire.');
