<?php

declare(strict_types=1);

// Task VMs reach the model router only through ModelKeyIssuer (docs/reference/compute-drivers.md#configure-task-vms).
arch('task VM code never uses proxycli code')
    ->expect(['App\Domain\TaskVms', 'App\Infrastructure\TaskVms', 'App\Actions\TaskVms', 'App\Jobs\TaskVms'])
    ->not->toUse(['App\Domain\ProxyCli', 'App\Infrastructure\ProxyCli', 'App\Actions\ProxyCli', 'App\Data\ProxyCli']);
