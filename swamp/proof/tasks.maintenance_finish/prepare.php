<?php

declare(strict_types=1);

/*
 * A settling maintenance group whose passed check found no change and that owns no workspace. The Gateway admits
 * the native cleanup through the same intent its maintenance continuation calls; there is no API route for it.
 */

use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(TaskExtensionState::class)->enable();
$project = Project::create(['name' => 'Maintenance finish proof', 'slug' => 'maintenance-finish-proof',
    'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'task_check' => 'true']);
$group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Maintenance finish proof', 'brief' => 'No-change routine.',
    'status' => 'running', 'orchestration' => 'external', 'maintenance' => ['mode' => 'routine', 'stage' => 'tasks.check']]);
Task::create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Maintain', 'brief' => 'Maintain.', 'status' => 'completed', 'deliverables' => []]);
$check = Operation::create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $group->id,
    'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['outcome' => 'passed'], 'state' => []]);
$group->update(['status' => 'settling', 'maintenance' => [...$group->maintenance, 'changed' => false, 'check_operation_id' => $check->id]]);
$operation = app(TaskCancelIntent::class)->finishMaintenance($group->fresh());
echo json_encode(['secrets' => ['operation' => $operation->id], 'group' => $group->id]), PHP_EOL;
