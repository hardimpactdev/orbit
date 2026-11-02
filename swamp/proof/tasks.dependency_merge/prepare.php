<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$targetId, $operatorId, $run] = array_slice($argv, 1);
app(TaskExtensionState::class)->enable();
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
$node = Node::findOrFail((int) $targetId);
$node->roles()->delete();
$node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
$project = Project::firstOrCreate(['repository_url' => 'https://github.com/hardimpactdev/orbit.git'], ['name' => 'Dependency merge proof', 'slug' => 'dependency-merge-'.$run, 'type' => 'laravel-app', 'default_branch' => 'main']);
$instance = Instance::firstOrCreate(['project_id' => $project->id, 'name' => 'production'], ['node_id' => $node->id, 'environment' => 'production', 'source_layout' => 'release', 'checkout_path' => '/home/orbit-app-'.$project->id.'/releases/proof', 'production_user' => 'orbit-app-'.$project->id, 'production_home' => '/home/orbit-app-'.$project->id, 'root' => 'public', 'branch' => 'main', 'status' => 'active']);
$cluster = Cluster::firstOrCreate(['name' => 'dependency-proof'], ['state' => 'active']);
$node->update(['cluster_id' => $cluster->id]);
$route = Route::firstOrCreate(['domain' => 'dependency-proof.example.test'], ['project_id' => $project->id, 'cluster_id' => $cluster->id, 'status' => 'pending', 'publication' => 'public', 'provenance' => 'explicit']);
$route->targets()->firstOrCreate(['instance_id' => $instance->id], ['position' => 0]);
$route->update(['status' => 'active']);
$group = Task::topLevel()->firstOrCreate(['project_id' => $project->id], ['title' => 'Dependency batch', 'brief' => 'Swamp schedule: batch-'.str_repeat('b', 64), 'created_by_node_id' => (int) $operatorId, 'status' => 'running', 'orchestration' => 'external']);
$url = 'https://github.com/hardimpactdev/orbit/pull/3';
$group->update(['pr_url' => $url, 'watched_pr_url' => $url, 'watched_pr_number' => 3, 'watched_pr_state' => 'open']);
$task = Task::firstOrCreate(['parent_id' => $group->id, 'position' => 1], ['title' => 'Update dependencies', 'brief' => 'Dependency batch.', 'status' => 'running', 'deliverables' => [['id' => 'package', 'type' => 'review', 'description' => 'Review.']]]);
$head = str_repeat('a', 40);
$check = TaskCheck::create(['task_id' => $task->id, 'kind' => 'handoff', 'status' => 'passed', 'pid' => 1, 'process_started' => 'proof', 'head_before' => $head, 'tree_before' => $head, 'commit_sha' => $head, 'started_at' => now(), 'finished_at' => now()]);
$publish = Operation::create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 209, 'status' => 'succeeded', 'input' => ['check' => $check->id], 'result' => ['branch' => 'task-'.$group->id, 'commit' => $head, 'pull_request_number' => 3, 'pull_request_url' => $url], 'started_at' => now(), 'finished_at' => now()]);
// Only this disposable proof Gateway enables the fixture Project. Production stays off.
file_put_contents(config_path('dependency_merge.php'), '<?php return '.var_export(['projects' => [$project->id => ['enabled' => true, 'instance_id' => $instance->id, 'smoke_workflow' => '.github/workflows/production-smoke.yml']]], true).';');
$detection = config('dependency_detection');
$detection['services_node_id'] = (int) $operatorId;
file_put_contents(config_path('dependency_detection.php'), '<?php return '.var_export($detection, true).';');
echo json_encode(['secrets' => ['group' => $group->id, 'publish' => $publish->id]]), PHP_EOL;
