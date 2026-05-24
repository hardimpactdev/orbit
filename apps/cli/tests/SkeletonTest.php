<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use LaravelZero\Framework\Application;

it('boots the CLI application with registered commands', function (): void {
    expect($this->app)
        ->toBeInstanceOf(Application::class);

    $commands = $this->app
        ->make(Kernel::class)
        ->all();

    expect($commands)->not->toBeEmpty();
});
