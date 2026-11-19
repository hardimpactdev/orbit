<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Laravel\Boost\Console\UpdateCommand;

it('leaves the hand-written AGENTS.md alone when Boost updates its guidance', function (): void {
    // Boost registers its commands only in a local or debug environment.
    app(Kernel::class)->registerCommand(app(UpdateCommand::class));
    $before = file_get_contents(base_path('AGENTS.md'));

    expect(Artisan::call('boost:update', ['--no-discover' => true, '--no-interaction' => true]))->toBe(0)
        ->and(file_get_contents(base_path('AGENTS.md')))->toBe($before)
        ->and(base_path('.ai'))->not->toBeDirectory()
        ->and(base_path('.agents'))->not->toBeDirectory();
});
