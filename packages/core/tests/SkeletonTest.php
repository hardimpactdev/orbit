<?php

declare(strict_types=1);

use Hardimpactdev\OrbitCore\OrbitCoreServiceProvider;

it('can instantiate the service provider', function (): void {
    expect(class_exists(OrbitCoreServiceProvider::class))->toBeTrue();

    expect(new OrbitCoreServiceProvider(null))
        ->toBeInstanceOf(OrbitCoreServiceProvider::class);
});
