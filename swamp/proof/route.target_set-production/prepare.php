<?php

declare(strict_types=1);

use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$target, $peer, $router] = array_map(intval(...), array_slice($argv, 1));
$cluster = Cluster::query()->create(['name' => 'proof-target-production', 'state' => 'active', 'tld' => 'proof.test']);
foreach ([$target, $peer, $router] as $id) {
    $node = Node::query()->findOrFail($id);
    $node->forceFill(['cluster_id' => $cluster->id, 'lan_ip' => $node->wireguard_ip])->save();
    $node->roles()->delete();
    $node->roles()->create(['role' => $id === $router ? 'router' : 'app-prod', 'status' => 'active',
        'cluster_id' => $id === $router ? $cluster->id : null]);
}
$project = Project::query()->forceCreate(['id' => 9009, 'name' => 'Production target proof', 'slug' => 'proof-target-production',
    'type' => 'monorepo', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
$instances = [];
foreach (['old' => $target, 'new' => $peer] as $name => $id) {
    $instances[$name] = Instance::query()->create(['project_id' => $project->id, 'node_id' => $id, 'name' => $name,
        'environment' => 'production', 'source_layout' => 'release', 'production_user' => 'orbit-app-9009',
        'production_home' => '/home/orbit-app-9009', 'checkout_path' => '/home/orbit-app-9009/releases/initial',
        'branch' => 'main', 'root' => 'public', 'source_is_laravel' => false, 'selected_php_version' => null,
        'provisioning_step' => 'active', 'status' => 'active']);
}
echo json_encode(['bindings' => ['old' => $instances['old']->id, 'new' => $instances['new']->id]], JSON_THROW_ON_ERROR), PHP_EOL;
