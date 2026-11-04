<?php

declare(strict_types=1);

use App\Models\GatewayRelease;

// Prints one release record's outcome, error code and phases for the parity check.
chdir(dirname(__DIR__, 3).'/apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(GatewayRelease::query()->findOrFail((int) $argv[1])->only(['outcome', 'error_code', 'phases']), JSON_THROW_ON_ERROR)."\n";
