<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Projects\ScheduledProjectMaintenance;
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
        $state = json_decode(Setting::query()->where('key', ScheduledProjectMaintenance::StateKey)->value('value') ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        if (($state['terminal']['run_id'] ?? null) === $argv[1]) {
            echo json_encode(['projected' => $argv[1]]), PHP_EOL;
            exit(0);
        }
        usleep(200_000);
    } while (microtime(true) < $deadline);
} finally {
    $subscriber->close();
}
throw new RuntimeException('The shared subscriber did not record the native maintenance selection.');
