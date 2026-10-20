<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

// The Gateway does not discover app/Console/Commands at boot; each command is registered by its owning provider.
it('registers every command in app/Console/Commands with Artisan', function (): void {
    $commands = [];
    foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $file) {
        $class = 'App\\Console\\Commands\\'.basename($file, '.php');
        if (is_subclass_of($class, Command::class) && ! new ReflectionClass($class)->isAbstract()) {
            $commands[] = $class;
        }
    }
    $registered = array_map(get_class(...), Artisan::all());

    expect($commands)->not->toBeEmpty()
        ->and(array_values(array_diff($commands, $registered)))->toBe([]);
});
