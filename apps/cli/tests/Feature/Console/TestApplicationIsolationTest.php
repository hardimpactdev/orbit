<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\CachedState;

it('reuses resolved configuration without reusing application state', function (): void {
    $original = $this->app;
    $defaults = CachedState::$cachedConfig;
    $name = config('app.name');

    expect(app('config_loaded_from_cache'))->toBeTrue();
    config()->set('app.name', 'changed by one case');
    app()->instance('test.case.sentinel', new stdClass);

    $this->refreshApplication();

    expect($this->app)->not->toBe($original)
        ->and(config('app.name'))->toBe($name)
        ->and(app()->bound('test.case.sentinel'))->toBeFalse()
        ->and(app('config_loaded_from_cache'))->toBeTrue()
        ->and(CachedState::$cachedConfig)->toBe($defaults);
});
