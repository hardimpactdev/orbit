<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Orbit\Extensions\Conn\ConnServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php')
    ->withProviders([ConnServiceProvider::class])
    ->withExceptions()
    ->create();
