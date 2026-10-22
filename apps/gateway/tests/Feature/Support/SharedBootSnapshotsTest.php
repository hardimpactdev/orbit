<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('shares stock boot snapshots without sharing case configuration or route changes', function (): void {
    expect(class_uses_recursive($this))->toContain(WithCachedConfig::class, WithCachedRoutes::class)
        ->and(CachedState::$cachedConfig)->not->toBeNull()
        ->and(CachedState::$cachedRoutes)->not->toBeNull();
    $configuration = CachedState::$cachedConfig;
    $routes = CachedState::$cachedRoutes;

    config(['app.name' => 'case-only application name']);
    Route::get('/case-only-boot-snapshot-fixture', static fn (): string => 'case only');

    expect(config('app.name'))->toBe('case-only application name')
        ->and(Route::getRoutes()->match(Request::create('/case-only-boot-snapshot-fixture'))->uri())->toBe('case-only-boot-snapshot-fixture')
        ->and(CachedState::$cachedConfig)->toBe($configuration)
        ->and(CachedState::$cachedRoutes)->toBe($routes);
});
