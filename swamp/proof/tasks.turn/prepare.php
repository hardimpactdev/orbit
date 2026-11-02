<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(TaskExtensionState::class)->enable();
[$nodeId, $head, $tree, $run] = array_slice($argv, 1);
$node = Node::findOrFail((int) $nodeId);
$project = Project::create(['name' => 'Turn proof', 'slug' => 'turn-'.$run, 'type' => 'node-package', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
$group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Turn proof', 'brief' => 'Synthetic Pi fixture.', 'status' => 'running', 'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6.1-sol']);
$task = Task::create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Turn', 'brief' => 'Turn.', 'status' => 'running', 'deliverables' => []]);
$instance = Instance::create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/home/orbit/turn-proof', 'status' => 'source_resolved']);
$group->taskable()->associate($instance)->save();
TaskVm::create(['group_id' => $group->id, 'node_id' => $node->id, 'provider' => 'upcloud', 'name' => 'proof-turn-'.$group->id, 'state' => 'ready', 'wireguard_ip' => $node->wireguard_ip, 'pi_token' => 'synthetic-pi-test-token-with-at-least-32-characters']);
$check = TaskCheck::create(['task_id' => $task->id, 'kind' => 'handoff', 'status' => 'passed', 'pid' => 1, 'process_started' => 'proof', 'head_before' => $head, 'tree_before' => $tree, 'head_after' => $head, 'tree_after' => $tree, 'commit_sha' => $head, 'started_at' => now(), 'finished_at' => now()]);
Operation::create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id, 'status' => 'succeeded', 'triggered_by' => 'proof', 'input' => ['subtask' => $task->id, 'phase' => 'handoff', 'check_id' => $check->id], 'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $head, 'tree' => $tree, 'ci' => null], 'started_at' => now(), 'finished_at' => now()]);
echo json_encode(['secrets' => ['group' => $group->id, 'subtask' => $task->id, 'check' => $check->id]]), PHP_EOL;
