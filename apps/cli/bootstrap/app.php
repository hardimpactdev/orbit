<?php

declare(strict_types=1);

use App\Console\Kernel as OrbitKernel;
use Illuminate\Contracts\Console\Kernel;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))->create();

$app->singleton(Kernel::class, OrbitKernel::class);

return $app;
