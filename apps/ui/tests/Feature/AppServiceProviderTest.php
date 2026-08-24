<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Vite as ViteFacade;

it('configures the testing hot file path when booting in the testing environment', function () {
    app()->forgetInstance(Vite::class);
    (new AppServiceProvider(app()))->boot();

    expect(ViteFacade::hotFile())->toBe(storage_path('framework/testing/vite.hot'));
});
