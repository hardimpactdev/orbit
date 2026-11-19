<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Spatie\Guidelines\GuidelinesServiceProvider;

$providers = [
    AppServiceProvider::class,
];

if (class_exists(GuidelinesServiceProvider::class)) {
    $providers[] = GuidelinesServiceProvider::class;
}

return $providers;
