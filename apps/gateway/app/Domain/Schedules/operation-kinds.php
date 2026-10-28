<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['create' => 'install', 'destroy' => 'remove', 'enable' => 'activate', 'run' => 'run'] as $verb => $command) {
        $fields = match ($verb) {
            'create' => ['id', 'user', 'group', 'home', 'working_directory', 'shell', 'login_shell', 'command', 'calendar', 'timeout_seconds', 'desired'],
            'destroy' => ['id', 'group', 'cascade'],
            default => ['id', 'user', 'working_directory'],
        };
        $run = ['transport' => 'agent', 'name' => 'schedule.'.$command, 'input_fields' => $fields, 'input_values' => []];
        if ($verb === 'create') {
            $run['secrets'] = ['command' => 'schedule.command'];
        }
        $kinds['schedule.'.$verb] = ['executor' => 'swamp', 'completion' => ScheduleOperationCompletion::class,
            'runs' => [$run], 'children' => [],
            'completion_contract' => ['step' => 'schedule-'.$command, 'transport' => 'agent', 'name' => 'schedule.'.$command, 'result' => []],
            'result_fields' => ['error_code']];
    }

    return $kinds;
})();
