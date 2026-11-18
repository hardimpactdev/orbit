<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Cache::forever('spike:create-engine', ($argv[1] ?? '') === 'on');
echo 'spike:create-engine = ', var_export(Illuminate\Support\Facades\Cache::get('spike:create-engine'), true), PHP_EOL;
