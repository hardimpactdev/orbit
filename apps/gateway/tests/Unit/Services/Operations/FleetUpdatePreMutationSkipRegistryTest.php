<?php

declare(strict_types=1);

use App\Services\Operations\FleetUpdatePreMutationSkipRegistry;

it('forgets recorded skips for an operation after success or failure', function (): void {
    $registry = new FleetUpdatePreMutationSkipRegistry;
    $registry->record('op-1', 'mini', 'orbit_desktop_not_running');
    $registry->record('op-2', 'other', 'orbit_desktop_not_running');

    $registry->forget('op-1');

    expect($registry->forOperation('op-1'))
        ->toBe([])
        ->and($registry->skipped('op-1', 'mini'))
        ->toBeFalse()
        ->and($registry->reason('op-2', 'other'))
        ->toBe('orbit_desktop_not_running');
});
