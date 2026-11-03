<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Models\Schedule;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$copy = Schedule::findOrFail($argv[1]);
$operation = app(StartOperationAction::class)->execute('schedule.enable', 'instance_schedules', $copy->target_id, $copy->host_node_id,
    ['id' => $copy->id], 'operator', null);
echo json_encode(['bindings' => ['enable' => $operation->id]]), PHP_EOL;
