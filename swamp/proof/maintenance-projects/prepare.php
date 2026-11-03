<?php

declare(strict_types=1);

use App\Domain\Projects\ScheduledProjectMaintenance;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Project::create(['name' => 'Maintenance opt-out proof', 'slug' => 'maintenance-opt-out-proof',
    'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main',
    'source_access' => 'github_app', 'task_check' => 'true', 'maintenance' => false]);
app(TaskExtensionState::class)->enable();
app(ScheduledProjectMaintenance::class)->activate();
$state = json_decode(Setting::query()->where('key', ScheduledProjectMaintenance::StateKey)->value('value'), true, flags: JSON_THROW_ON_ERROR);
$inputs = $state['inputs'];
if ($inputs['projects'] !== [] || $inputs['groups'] !== [] || $inputs['operations'] !== []) {
    throw new RuntimeException('The opt-out Project must not appear in maintenance selection.');
}
echo json_encode(['bindings' => ['revision' => $inputs['revision'], 'snapshot_hash' => $inputs['snapshot_hash']]]), PHP_EOL;
