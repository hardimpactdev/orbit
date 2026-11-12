<?php

declare(strict_types=1);

// Test support on the mounted disposable Gateway. No request or execution doubles.
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

require __DIR__.'/../../apps/gateway/vendor/autoload.php';
$app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

try {
    if (trim((string) file_get_contents('/etc/hostname')) !== 'nested-gateway'
        || Node::query()->where('name', 'gateway')->where('wireguard_ip', '10.45.0.1')->count() !== 1) {
        throw new RuntimeException('proof.instance_fixture_not_nested');
    }
    $node = Node::query()->where('name', 'app-dev')->where('wireguard_ip', '10.45.0.2')->sole();
    $mode = $input['mode'];
    if ($mode === 'prepare') {
        // Read the actual contributions and guard. Never fabricate their successful state.
        app(DevelopmentInstanceCreateAdmission::class)->assertRuntime();
        if (! $app->bound(TaskVmModelRoute::class)) {
            throw new RuntimeException('proof.instance_model_route_missing');
        }
        if (Instance::query()->where('node_id', $node->id)->exists()) {
            throw new RuntimeException('proof.instance_fixture_requires_clean_node');
        }
        Config::set('instances.container_nodes', [$node->id]);
        $host = app(InstanceHostIntent::class);
        $host->prepare($node);
        $result = ['instances' => $host->for($node), 'proof_instance_node_id' => $node->id];
    } else {
        $slug = $input['fixture'] ?? '';
        if (! is_string($slug) || preg_match('/\Aproof-clone-[a-f0-9]{32}\z/D', $slug) !== 1) {
            throw new RuntimeException('proof.instance_fixture_owner_invalid');
        }
        $project = Project::query()->where('slug', $slug)->first();
        if ($project !== null && ($project->repository_url !== 'https://github.com/octocat/Hello-World.git'
            || $project->instances()->where('node_id', '!=', $node->id)->exists())) {
            throw new RuntimeException('proof.instance_fixture_owner_mismatch');
        }
        $instances = $project === null ? collect() : $project->instances()->with('runtime')->get();
        $rows = $instances->map(static fn (Instance $instance): array => [
            'id' => $instance->id, 'name' => $instance->name, 'status' => $instance->status->value,
            'runtime' => $instance->runtime?->only(['incus_project', 'container_name', 'bridge_address', 't3_port',
                'clone_source_instance_id', 'seed_commit', 'power_state']),
            'mirror' => App\Domain\Instances\InstanceContainer::mirror($instance),
            'conn' => ConnEnvironment::query()->where('instance_id', $instance->id)->pluck('environment_id')->all(),
        ])->all();
        $result = ['project' => $project?->id, 'instances' => $rows];
        if (isset($input['instance'])) {
            $id = (int) $input['instance'];
            $result['runtime_exists'] = InstanceRuntime::query()->where('instance_id', $id)->exists();
            $result['conn_exists'] = ConnEnvironment::query()->where('instance_id', $id)->exists();
        }
        if (isset($input['operation'])) {
            $operation = Operation::query()->findOrFail((int) $input['operation']);
            $container = $operation->input['direct_provisioning']['container'] ?? null;
            $result['operation'] = [
                'kind' => $operation->kind, 'target_id' => $operation->target_id, 'status' => $operation->status->value,
                'source' => $container['clone']['source'] ?? null,
                'seed_commit' => $container['clone']['seed_commit'] ?? null,
            ];
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    // No exception message or arguments: setup may have inspected protected settings.
    $code = $error instanceof App\Domain\Shared\ResourceOperationException ? $error->errorCode : 'proof.instance_fixture_failed';
    fwrite(STDERR, $code.' ('.get_class($error).')'."\n");
    exit(1);
}
