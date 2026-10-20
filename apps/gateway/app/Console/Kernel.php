<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Artisan without command discovery. Each service provider registers its commands with `commands()`, which Artisan
 * resolves only when it starts, so booting the Gateway loads no command class. Without discovery, Artisan also loads no
 * `routes/console.php`. It is not final, because a test that mocks the Artisan facade mocks this class.
 */
class Kernel extends ConsoleKernel
{
    #[\Override]
    protected function shouldDiscoverCommands(): bool
    {
        return false;
    }
}
