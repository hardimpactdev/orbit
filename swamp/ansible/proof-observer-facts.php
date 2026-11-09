<?php

declare(strict_types=1);

use App\Domain\Proof\ProofObserverReadiness;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$app = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$readiness = $app->make(ProofObserverReadiness::class);
if ($argv[1] === 'reset') {
    $readiness->reset();
    exit(0);
}
exit($readiness->ready($argv[2]) ? 0 : 1);
