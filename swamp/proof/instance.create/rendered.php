<?php

declare(strict_types=1);

// Prints the SHA-256 of the .env the topology Gateway renders for an Instance; run in apps/gateway. The digest stays
// out of the public Operation input, so parity recomputes it here.

use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Models\Instance;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo hash('sha256', app(DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail((int) $argv[1]))), PHP_EOL;
