<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
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
[$nodeId, $run] = array_slice($argv, 1);
$guest = Node::findOrFail((int) $nodeId);
$host = Node::findOrFail(209);
$host->update(['settings' => [...($host->settings ?? []), 'task_vms' => [
    'project' => 'default', 'network' => 'orbittaskp', 'cidr' => '10.232.0.0/24',
    'max_vms' => 1, 'cpus' => 1, 'memory' => '512MiB', 'disk' => '10GiB', 'pool' => 'default',
]]]);
$project = Project::create(['name' => 'Park proof', 'slug' => 'park-'.$run, 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
$group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Park proof', 'brief' => 'Disposable nested provider VM.',
    'status' => 'running', 'orchestration' => 'external', 'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/999999999', 'watched_pr_state' => 'open']);
Task::create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Complete', 'brief' => 'Complete.', 'status' => 'completed']);
$instance = Instance::create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'park-proof',
    'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/park-proof-'.$run, 'status' => 'source_resolved']);
$group->taskable()->associate($instance)->save();
$vm = TaskVm::create(['group_id' => $group->id, 'node_id' => $guest->id, 'host_node_id' => $host->id, 'provider' => 'incus',
    'name' => 'pending-park-proof', 'wireguard_ip' => $guest->wireguard_ip, 'state' => 'ready']);
$vm->update(['name' => 'tvm-'.$vm->id]);
echo json_encode(['secrets' => ['group' => $group->id, 'vm_name' => $vm->name]]), PHP_EOL;
