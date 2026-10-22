<?php

declare(strict_types=1);

it('builds fresh configuration without reusing application state', function (): void {
    $original = $this->app;
    $name = config('app.name');

    expect(app('config_loaded_from_cache'))->toBeFalse();
    config()->set('app.name', 'changed by one case');
    app()->instance('test.case.sentinel', new stdClass);

    $this->refreshApplication();

    expect($this->app)->not->toBe($original)
        ->and(config('app.name'))->toBe($name)
        ->and(app()->bound('test.case.sentinel'))->toBeFalse()
        ->and(app('config_loaded_from_cache'))->toBeFalse()
        ->and($original->make('config')->get('app.name'))->toBe('changed by one case');
});
