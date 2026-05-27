<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$repoRoot = dirname(__DIR__);
$gatewayRoot = $repoRoot.'/apps/gateway';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $repoRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
$autoload = $gatewayRoot.'/vendor/autoload.php';

if (! is_file($autoload)) {
    $autoload = $repoRoot.'/vendor/autoload.php';
}

require $autoload;

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $gatewayRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
