<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\ProcessDefinition;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$node = Node::findOrFail($input['node']);
$node->roles()->delete();
$node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
$project = Project::find(9002) ?? new Project;
$project->id = 9002;
$project->fill(['name' => 'Production deploy proof', 'slug' => 'proof-production-deploy', 'type' => 'laravel-app',
    'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public'])->save();
$instance = Instance::find(9002) ?? new Instance;
$instance->id = 9002;
$instance->fill(['project_id' => 9002, 'node_id' => $node->id, 'name' => 'production', 'environment' => 'production',
    'source_layout' => 'release', 'checkout_path' => '/home/orbit-app-9002/releases/'.($input['case'] === 'first' ? 'initial' : 'r3'),
    'production_user' => 'orbit-app-9002', 'production_home' => '/home/orbit-app-9002', 'root' => 'public', 'branch' => 'main',
    'selected_php_version' => '8.5', 'source_is_laravel' => true, 'provisioning_step' => 'active', 'status' => 'active',
    'production_php_service' => null, 'production_php_pool' => null, 'production_php_socket' => null,
    'environment_owned_keys' => [], 'auto_deploy_state' => null])->save();
if ($input['case'] !== 'first') {
    $instance->fill(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes())->save();
}
$instance->deploySteps()->delete();
$instance->environmentValues()->delete();
InstanceDeployment::where('instance_id', $instance->id)->delete();
if ($input['case'] !== 'first') {
    foreach (['r1', 'r2', 'r3'] as $release) {
        InstanceDeployment::create(['instance_id' => $instance->id, 'kind' => 'deploy', 'branch' => 'main', 'release' => $release,
            'selected_release' => $release, 'commit' => $input['previous'], 'status' => 'succeeded', 'started_at' => now()->subMinute(),
            'finished_at' => now(), 'triggered_by' => 'proof']);
    }
}
ProcessDefinition::firstOrCreate(['project_id' => $project->id, 'name' => 'proof-deploy'],
    ['environments' => ['production'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', 'infinity']]]);
echo '{}', PHP_EOL;
