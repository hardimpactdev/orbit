<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskMaintenanceIntent;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(TaskExtensionState::class)->enable();
[$nodeId, $workspace, $base] = array_slice($argv, 1);
$node = Node::findOrFail((int) $nodeId);
$project = Project::create(['name' => 'Maintenance proof', 'slug' => 'maintenance-proof',
    'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'task_check' => 'true']);
$group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Maintenance proof', 'brief' => 'Empty Composer workspace.',
    'status' => 'running', 'orchestration' => 'external', 'maintenance' => ['mode' => 'routine', 'stage' => 'tasks.maintenance',
        'project' => ['projectId' => $project->id, 'repository' => $project->repository_url, 'defaultBranch' => 'main', 'check' => 'true',
            'worker' => ['composerPaths' => ['.'], 'jsPaths' => [], 'policy' => []]], 'targets' => []]]);
Task::create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Maintain', 'brief' => 'Maintain.', 'status' => 'todo', 'deliverables' => []]);
$instance = Instance::create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'maintenance-proof',
    'task_workspace_owner_id' => $group->id, 'checkout_path' => $workspace, 'starting_commit' => $base,
    'branch_override' => 'main', 'source_layout' => 'checkout', 'status' => 'source_resolved']);
$group->taskable()->associate($instance)->save();
TaskVm::create(['group_id' => $group->id, 'node_id' => $node->id, 'host_node_id' => 209, 'provider' => 'incus',
    'name' => 'tvm-maintenance-proof', 'wireguard_ip' => $node->wireguard_ip, 'state' => 'ready']);
$operation = app(TaskMaintenanceIntent::class)->start($group);
$group->update(['maintenance' => [...$group->maintenance, 'operation_id' => $operation->id]]);
// Prove this root only. The real disabled-extension rule refuses its downstream handoff admission.
app(TaskExtensionState::class)->disable();
echo json_encode(['secrets' => ['operation' => $operation->id]]), PHP_EOL;
