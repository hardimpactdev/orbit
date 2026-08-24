<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        config(['inertia.ssr.enabled' => false]);

        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Browser');

expect()->extend('toBeOne', fn () => $this->toBe(1));
