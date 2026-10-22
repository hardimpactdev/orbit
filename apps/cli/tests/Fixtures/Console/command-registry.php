<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\CommandRegistrySnapshot;

$_SERVER['PHP_SELF'] = $argv[1];
$_SERVER['SCRIPT_FILENAME'] = $argv[2];

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo json_encode(CommandRegistrySnapshot::capture($kernel), JSON_THROW_ON_ERROR);
