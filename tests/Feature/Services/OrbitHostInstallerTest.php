<?php

declare(strict_types=1);

use App\Services\OrbitHostInstaller;
use Illuminate\Support\Facades\Process;

it('copies bootstrap authorized keys to the runtime user before installing orbit', function (): void {
    Process::fake(fn () => Process::result());

    app(OrbitHostInstaller::class)->install('192.0.2.10', 'root', 'orbit');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'root'@'192.0.2.10'")
        && str_contains((string) $process->command, 'BOOTSTRAP_KEYS="/root/.ssh/authorized_keys"')
        && str_contains((string) $process->command, 'TARGET_KEYS="/home/$USER/.ssh/authorized_keys"')
        && str_contains((string) $process->command, 'sudo grep -qxF "$key" "$TARGET_KEYS"'));
});
