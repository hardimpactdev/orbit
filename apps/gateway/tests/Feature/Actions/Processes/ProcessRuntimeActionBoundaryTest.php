<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('routes process lifecycle actions through the runtime driver registry', function (): void {
    $actionFiles = [
        'AddProcess' => base_path('app/Actions/Processes/AddProcess.php'),
        'EditProcess' => base_path('app/Actions/Processes/EditProcess.php'),
        'StartProcesses' => base_path('app/Actions/Processes/StartProcesses.php'),
        'StopProcesses' => base_path('app/Actions/Processes/StopProcesses.php'),
        'RestartProcesses' => base_path('app/Actions/Processes/RestartProcesses.php'),
        'ShowProcessLogs' => base_path('app/Actions/Processes/ShowProcessLogs.php'),
        'RemoveProcess' => base_path('app/Actions/Processes/RemoveProcess.php'),
        'EnsureAppProcessRuntimeUnits' => base_path('app/Actions/Apps/EnsureAppProcessRuntimeUnits.php'),
    ];

    foreach ($actionFiles as $action => $path) {
        $source = file_get_contents($path);

        if ($action === 'EditProcess') {
            $source .= file_get_contents(base_path('app/Actions/Processes/EditProcessRuntimeUnits.php'));
            $source .= file_get_contents(base_path('app/Actions/Processes/EditProcessRuntimeUnitCleaner.php'));
        }

        if (in_array($action, ['StartProcesses', 'StopProcesses', 'RestartProcesses'], strict: true)) {
            $source .= file_get_contents(base_path('app/Services/Processes/ProcessRuntimeTargets.php'));
        }

        expect(str_contains($source, 'ProcessRuntimeDriverRegistry'))
            ->toBeTrue("{$action} must resolve lifecycle through ProcessRuntimeDriverRegistry.")
            ->and(str_contains($source, 'ProcessDockerRuntimeManager'))
            ->toBeFalse("{$action} must not depend on ProcessDockerRuntimeManager directly.")
            ->and(str_contains($source, 'supervisorctl'))
            ->toBeFalse("{$action} must not shell supervisorctl directly.");
    }
});
