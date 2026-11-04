<?php

declare(strict_types=1);

// One bounded harness phase. Native Swamp owns sequencing and failure handling.
use App\E2E\State\StatePaths;
use App\E2E\IncusHost;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyReleaser;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use Illuminate\Contracts\Console\Kernel;

try {
$request = json_decode(file_get_contents(getenv('PROOF_REQUEST')), true, flags: JSON_THROW_ON_ERROR);
$source = $argv[2];
require $source.'/apps/e2e/vendor/autoload.php';
$app = require $source.'/apps/e2e/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Every isolated source copy still shares the existing host-wide creation locks.
$app->instance(StatePaths::class, StatePaths::forPrimary($request['candidate']));
$target = new TopologyRequest($request['issue'], $source);
if ($argv[1] === 'prepare') {
    $prepared = $app->make(TopologyAcquirer::class)->prepare($target, TopologyRecipe::compose(['app-dev', 'app-prod']));
    echo json_encode([
        'construction' => $prepared['construction']->toArray(),
        'source' => $prepared['source']->toArray(),
        'mounts' => $prepared['mounts'],
        'ready' => false,
    ], JSON_THROW_ON_ERROR), PHP_EOL;
} elseif ($argv[1] === 'wait-agent') {
    $app->make(IncusHost::class)->waitForAgents([$argv[3]]);
    echo json_encode(['ready' => true], JSON_THROW_ON_ERROR), PHP_EOL;
} elseif ($argv[1] === 'release') {
    echo json_encode($app->make(TopologyReleaser::class)->release($target, sweepOrphans: false), JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    throw new InvalidArgumentException('Unknown proof topology phase.');
}
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
