<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$steps = array_values(array_filter(array_slice($argv, 1)));
Illuminate\Support\Facades\Cache::forever('spike:create-faults', $steps === [] ? [] : ['fail_step' => $steps]);
echo 'spike:create-faults = ', json_encode(Illuminate\Support\Facades\Cache::get('spike:create-faults')), PHP_EOL;
