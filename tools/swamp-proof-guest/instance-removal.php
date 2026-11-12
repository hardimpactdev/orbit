<?php

declare(strict_types=1);

// Test support on the disposable nested Gateway only. Never loaded by a release.
use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../apps/gateway/vendor/autoload.php';
$app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! Node::query()->where('wireguard_ip', '10.45.0.1')->exists()) {
    throw new LogicException('Restore fixtures require the disposable nested Gateway.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (! preg_match('/\Arestore-proof-[a-f0-9]{12}\z/D', $input['slug'])) {
    throw new InvalidArgumentException('Invalid owned fixture slug.');
}
$slug = $input['slug'];
if ($input['mode'] === 'create') {
    $node = Node::query()->findOrFail($input['node_id']);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package',
        'repository_url' => "https://github.com/example/{$slug}.git", 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'fixture', 'status' => 'active', 'checkout_path' => "/home/orbit/apps/{$slug}",
        'root' => 'public', 'source_layout' => 'checkout', 'branch' => 'main', 'starting_commit' => $input['commit']]);
    $result = ['instance' => $instance->id];
} else {
    $instance = Instance::query()->where('project_id', Project::query()->where('slug', $slug)->sole()->id)->sole();
    $server = DatabaseServer::query()->where('slug', $slug)->sole();
    $connection = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->sole();
    $member = InstanceRemovalMember::query()->where('instance_id', $instance->id)->latest('id')->first();
    $removal = $member === null ? null : InstanceRemoval::query()->findOrFail($member->instance_removal_id);
    $destroy = $removal === null ? null : Operation::query()->where('kind', 'instance.destroy')->where('input->removal_id', $removal->id)->oldest('id')->firstOrFail();
    $route = $instance->routes()->with('targets')->sole();
    $result = ['instance' => $instance->id, 'instance_status' => $instance->status->value,
        'container' => app(DockerProcessRenderer::class)->containerName($server->process),
        'database' => $connection->database, 'route' => $route->id, 'route_status' => $route->status->value,
        'published' => $route->sites_published, 'domain' => $route->domain,
        'targets' => $route->targets->map->only(['id', 'instance_id', 'position'])->all(),
        'awake' => RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id)),
        'cold' => RuntimeHibernation::coldPath(RuntimeHibernation::key($instance->id)),
        'checkout' => $instance->checkout_path, 'commit' => $instance->starting_commit,
        'removal' => $removal?->id, 'removal_status' => $removal?->status->value,
        'abandoned' => $member?->abandoned_at !== null, 'deleted' => $member?->row_deleted_at !== null,
        'destroy_status' => $destroy?->status->value,
        'plan' => $removal?->native_intent['direct_destroy']['database']['servers'][0]['intent'] ?? null];
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
