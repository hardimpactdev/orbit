<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(TaskExtensionState::class)->enable();
[$nodeId, $head, $tree, $run] = array_slice($argv, 1);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
$node = Node::findOrFail((int) $nodeId);
$project = Project::firstOrCreate(['repository_url' => 'https://github.com/hardimpactdev/orbit.git'], ['name' => 'Publication proof', 'slug' => 'publish-'.$run, 'type' => 'node-package', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'review_and_merge' => true, 'merge_check' => 'Required checks']);
$group = Task::topLevel()->firstOrCreate(['project_id' => $project->id], [ 'title' => 'Publication proof', 'brief' => 'Disposable GitHub contract fixture.', 'status' => 'running', 'orchestration' => 'external']);
$group->update(['pr_url' => null, 'watched_pr_url' => null, 'watched_pr_number' => null, 'watched_pr_state' => null]);
$task = Task::firstOrCreate(['parent_id' => $group->id, 'position' => 1], [ 'title' => 'Publication', 'brief' => 'Publish.', 'status' => 'running', 'deliverables' => []]);
$instance = Instance::firstOrCreate(['task_workspace_owner_id' => $group->id], ['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/home/orbit/task-publish-proof', 'status' => 'source_resolved']);
$group->taskable()->associate($instance)->save();
TaskVm::updateOrCreate(['group_id' => $group->id], [ 'node_id' => $node->id, 'provider' => 'upcloud', 'name' => 'proof-publish-'.$group->id, 'state' => 'ready', 'wireguard_ip' => $node->wireguard_ip, 'pi_token' => 'synthetic-pi-test-token-with-at-least-32-characters']);
$check = TaskCheck::create(['task_id' => $task->id, 'kind' => 'handoff', 'status' => 'passed', 'pid' => 1, 'process_started' => 'proof', 'head_before' => $head, 'tree_before' => $tree, 'head_after' => $head, 'tree_after' => $tree, 'commit_sha' => $head, 'started_at' => now(), 'finished_at' => now()]);
$ci = ['state' => 'passed', 'fault' => null, 'commit' => $head, 'branch' => TaskRemoteBranch::handoff($group), 'check_name' => 'Required checks'];
Operation::create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id, 'status' => 'succeeded', 'triggered_by' => 'proof', 'input' => ['subtask' => $task->id, 'phase' => 'handoff', 'check_id' => $check->id], 'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $head, 'tree' => $tree, 'ci' => $ci], 'started_at' => now(), 'finished_at' => now()]);
echo json_encode(['secrets' => ['group' => $group->id, 'check' => $check->id]]), PHP_EOL;
