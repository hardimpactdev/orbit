<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

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
    'type' => 'laravel-app', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => '13.x', 'root' => 'public']);
$instances = [];
foreach (['old' => $target, 'new' => $peer] as $name => $id) {
    $instances[$name] = Instance::query()->create(['project_id' => $project->id, 'node_id' => $id, 'name' => $name,
        'environment' => 'production', 'source_layout' => 'release', 'production_user' => 'orbit-app-9009',
        'production_home' => '/home/orbit-app-9009', 'checkout_path' => '/home/orbit-app-9009/releases/initial',
        'branch' => '13.x', 'root' => 'public', 'source_is_laravel' => true, 'selected_php_version' => '8.5',
        'provisioning_step' => 'active', 'status' => 'active']);
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instances[$name], '8.5');
    $instances[$name]->fill($identity->attributes())->save();
    $config = app(ProductionPhpRuntimeConfigRenderer::class)->render($identity);
    $files = [$identity->generatedDirectory.'/php-fpm.conf' => $config->main,
        $identity->generatedDirectory.'/pool.conf' => $config->pool,
        $identity->generatedDirectory.'/runtime.ini' => $config->masterIni,
        $identity->localTuningPath => $config->localDefaults, $identity->unitPath => $config->unit];
    $write = new Process(['incus', 'exec', $name === 'old' ? 'target' : 'peer', '--', 'python3', '-I', '-c',
        'import json,os,sys; from pathlib import Path; files=json.load(sys.stdin); '
        .'[(p.parent.mkdir(parents=True,exist_ok=True),p.write_text(c),p.chmod(0o644)) for n,c in files.items() for p in [Path(n)]]']);
    $write->setInput(json_encode($files, JSON_THROW_ON_ERROR));
    $write->mustRun();
    (new Process(['incus', 'exec', $name === 'old' ? 'target' : 'peer', '--', 'systemctl', 'daemon-reload']))->mustRun();
    (new Process(['incus', 'exec', $name === 'old' ? 'target' : 'peer', '--', 'systemctl', 'enable', '--now', $identity->service]))->mustRun();
}
echo json_encode(['bindings' => ['old' => $instances['old']->id, 'new' => $instances['new']->id]], JSON_THROW_ON_ERROR), PHP_EOL;
