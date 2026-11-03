<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$nodeId, $run] = array_slice($argv, 1);
$node = Node::findOrFail((int) $nodeId);
$project = Project::create(['name' => 'Definition proof', 'slug' => 'definition-proof', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git']);
$instance = Instance::create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'definition-proof',
    'checkout_path' => '/home/orbit', 'source_layout' => 'checkout', 'status' => 'active']);
$members = [];
foreach (['copy'] as $name) {
    $definition = ScheduleDefinition::create(['project_id' => $project->id, 'name' => $name, 'environments' => ['production'],
        'spec' => ['command' => '/usr/bin/true', 'calendar' => 'daily', 'timeout_seconds' => 60]]);
    $copy = Schedule::create(['target_type' => Instance::MorphAlias, 'target_id' => $instance->id, 'host_node_id' => $node->id,
        'source_definition_id' => $definition->id, 'name' => $name, 'command' => '/usr/bin/true', 'calendar' => 'daily',
        'timeout_seconds' => 60, 'desired_timer_state' => 'disabled', 'status' => 'provisioning']);
    $members[] = ['id' => $copy->id, 'input' => ['id' => $copy->id, 'node_id' => $node->id, 'created' => false,
        'user' => 'orbit', 'group' => 'orbit', 'home' => '/home/orbit', 'working_directory' => '/home/orbit',
        'shell' => '/bin/bash', 'login_shell' => false, 'calendar' => 'daily', 'timeout_seconds' => 60,
        'desired' => 'disabled', 'command_hash' => hash('sha256', '/usr/bin/true')]];
}
// Supply the captured public plan at the same admission boundary used by Instance runtime preparation.
// Account lookup and full production cloning are outside this parent-completion proof.
$operation = app(StartOperationAction::class)->execute('schedule.create', 'instance_schedules', $instance->id, $node->id,
    $members[0]['input'], 'operator', 'proof-'.$run);
echo json_encode(['secrets' => ['operation' => $operation->id]]), PHP_EOL;
