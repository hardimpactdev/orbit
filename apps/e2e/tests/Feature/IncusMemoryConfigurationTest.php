<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('sizes newly created guests by Node with the uniform override', function (?string $override, string $node, string $memory, int $address): void {
    if ($override !== null) {
        config(['e2e.incus.memory' => $override]);
    }
    Process::fake(['*' => Process::result()]);

    app(IncusHost::class)->initGuests([$node => [
        'image' => 'orbit-e2e-'.$node,
        'name' => 'orbit-memory-'.$node,
        'network' => 'oe-memory',
        'node' => $node,
        'address' => $address,
        'slot' => 2,
        'container' => $node === 'operator',
        'metadata' => [],
    ]]);

    Process::assertRan(fn (PendingProcess $process): bool => (
        is_array($process->command)
        && in_array('init', $process->command, true)
        && in_array('limits.memory='.$memory, $process->command, true)
        && in_array('limits.cpu=1', $process->command, true)
    ));
})->with([
    'Gateway' => [null, 'gateway', '1536MiB', 10],
    'development' => [null, 'app-dev', '2GiB', 11],
    'production' => [null, 'app-prod', '1GiB', 12],
    'second production' => [null, 'app-prod-2', '1GiB', 13],
    'roleless operator container' => [null, 'operator', '512MiB', 14],
    'uniform Gateway override' => ['3GiB', 'gateway', '3GiB', 10],
    'uniform production override' => ['3GiB', 'app-prod', '3GiB', 12],
]);
