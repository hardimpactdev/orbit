<?php

declare(strict_types=1);

/*
 * Minimal proof Gateway driver, run in apps/gateway of the staged commit.
 *   seed     stdin {gateway, operator, target}: fixture Node records and access, nothing else.
 *   request  stdin {caller, method, path, body, key, seconds}: one real API request through the HTTP
 *            kernel from the caller's address, then the production SwampRunSubscriber follows the
 *            admitted Operation to its native terminal projection. Reverb, which drives the
 *            subscriber in production, is not part of the minimal topology.
 *   fires    stdin {schedule, seconds}: the same subscriber, with its shared run reader, until a calendar
 *            fire of that Schedule is recorded as a task.
 *   fixture  stdin {roles: {node_id: [role]}, replace_roles: {node_id: [role]}, wireguard_keys: {node_id: public key}, vpn_subnet?, projects: [attributes],
 *            extensions: [name], task_groups: [attributes + subtasks: [attributes] + taskable?: {instance: id}],
 *            instances: [attributes], task_vms: [attributes], operations: [attributes],
 *            clusters: [{name, tld?, node_ids, router_node_id}], routes: [{id, attributes}],
 *            instance_updates: [{id, attributes}]}: the records a kind fixture
 *            needs beyond the seed, such as the VPN hub, peers with keys and the VPN subnet, a Project under the fixed
 *            id its Swamp configuration names, an extension, or a task group's owned workspace (the group and its
 *            subtasks, its Instance, its task VM and its provision Operation) for kinds that run in it.
 */

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

function node(string $name, string $address, string $hostKey, array $roles = [], ?int $id = null, array $settings = []): Node
{
    $bytes = base64_decode($hostKey, true);
    $node = (new Node)->forceFill([...($id === null ? [] : ['id' => $id]),
        'name' => $name, 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
        'public_ssh_host' => $address, 'public_ssh_port' => 22, 'wireguard_ip' => $address,
        'wireguard_public_key' => base64_encode(random_bytes(32)), 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $hostKey,
        'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, true)), '='), 'status' => 'active',
        ...($settings === [] ? [] : ['settings' => $settings]),
    ]);
    $node->save();
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }

    return $node;
}

if ($argv[1] === 'seed') {
    if (Node::query()->exists()) {
        throw new RuntimeException('proof.seed_database_not_empty');
    }
    // Only the targets are reached over SSH; the other two need a well-formed key and no host.
    $unused = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32));
    // Records first, then one known-hosts publication with the complete pin snapshot, as after enrollment.
    [$gateway, $operator, $targets] = Node::withoutEvents(fn (): array => [
        // The Gateway hard-codes Node 209 as the Swamp Node; here this VM carries gateway and swamp together (F14).
        node('gateway', $input['gateway'], $input['gateway_host_key'], ['gateway', 'swamp'], 209),
        node('operator', $input['operator'], $unused),
        array_map(static fn (array $target): Node => node($target['key'], $target['address'], $target['host_key'],
            $target['roles'] ?? [], settings: $target['settings'] ?? []), $input['targets']),
    ]);
    // The verifier of the agent key up.py wrote on each target, as after enrollment.
    // No WireGuard in this topology: no target is a private DNS peer.
    foreach ($targets as $index => $target) {
        $target->forceFill(['agent_secret_hash' => $input['targets'][$index]['agent_secret_hash'], 'wireguard_public_key' => null])->saveQuietly();
    }
    app(GatewayServingHost::class)->remember($gateway);
    $operator->accessibleNodes()->attach([$gateway->id, ...array_map(static fn (Node $target): int => $target->id, $targets)]);
    app(WorkerKnownHostsPublisher::class)->publish();
    $nodes = ['gateway' => $gateway->id, 'operator' => $operator->id];
    foreach ($targets as $target) {
        $nodes[$target->name] = $target->id;
    }
    echo json_encode(['nodes' => $nodes]), PHP_EOL;
    exit(0);
}

if ($argv[1] === 'fixture') {
    // A Node's whole role set, for a kind that needs another placement than the topology's, such as app-prod.
    foreach ($input['replace_roles'] ?? [] as $id => $roles) {
        $node = Node::query()->findOrFail((int) $id);
        $node->roles()->whereNotIn('role', $roles)->delete();
        foreach ($roles as $role) {
            $node->roles()->firstOrCreate(['role' => $role], ['status' => 'active']);
        }
    }
    foreach ($input['roles'] ?? [] as $id => $roles) {
        foreach ($roles as $role) {
            Node::query()->findOrFail((int) $id)->roles()->firstOrCreate(['role' => $role], ['status' => 'active']);
        }
    }
    // Gateway extensions a kind's projection needs, such as tasks for Project maintenance groups.
    if (in_array('tasks', $input['extensions'] ?? [], true)) {
        app(TaskExtensionState::class)->enable();
    }
    // A Project under a fixed id, for kinds whose Swamp side keys source-owned configuration by Project id.
    foreach ($input['projects'] ?? [] as $project) {
        (new Project)->forceFill($project)->save();
    }
    // A task group's owned workspace, in the order its references need: the group and its subtasks, the Instance the
    // group owns, the group holding that Instance (a task holds only a workspace it owns), its task VM, and a
    // recorded Operation such as the provision that pinned the analysis base.
    $holds = [];
    foreach ($input['task_groups'] ?? [] as $group) {
        $subtasks = $group['subtasks'] ?? [];
        $taskable = $group['taskable'] ?? [];
        unset($group['subtasks'], $group['taskable']);
        $parent = (new Task)->forceFill($group);
        $parent->save();
        foreach ($subtasks as $subtask) {
            (new Task)->forceFill([...$subtask, 'parent_id' => $parent->id])->save();
        }
        if (isset($taskable['instance'])) {
            $holds[] = [$parent, $taskable['instance']];
        }
    }
    foreach ($input['instances'] ?? [] as $instance) {
        (new Instance)->forceFill($instance)->save();
    }
    foreach ($holds as [$group, $instance]) {
        $group->forceFill(['taskable_type' => 'instance', 'taskable_id' => $instance])->save();
    }
    foreach ($input['task_vms'] ?? [] as $vm) {
        (new TaskVm)->forceFill($vm)->save();
    }
    foreach ($input['operations'] ?? [] as $operation) {
        (new Operation)->forceFill($operation)->save();
    }
    foreach ($input['wireguard_keys'] ?? [] as $id => $key) {
        Node::query()->findOrFail((int) $id)->update(['wireguard_public_key' => $key]);
    }
    if (isset($input['vpn_subnet'])) {
        app(VpnSettings::class)->configure(subnet: $input['vpn_subnet']);
    }
    // Attributes a prepared Route needs for a kind, such as a failed Route whose sites stay published.
    foreach ($input['routes'] ?? [] as $route) {
        Route::query()->findOrFail((int) $route['id'])->forceFill($route['attributes'])->save();
    }
    // The same for a prepared Instance, such as an old Route target that is no longer active.
    foreach ($input['instance_updates'] ?? [] as $instance) {
        Instance::query()->findOrFail((int) $instance['id'])->forceFill($instance['attributes'])->save();
    }
        // An active Cluster with its member Nodes and its Router, for kinds that move work between Nodes, such as transfer.
    foreach ($input['clusters'] ?? [] as $spec) {
        $cluster = Cluster::query()->create(['name' => $spec['name'], 'tld' => $spec['tld'] ?? null, 'state' => 'active']);
        foreach ($spec['node_ids'] as $id) {
            Node::query()->findOrFail((int) $id)->forceFill(['cluster_id' => $cluster->id])->save();
        }
        Node::query()->findOrFail((int) $spec['router_node_id'])->roles()
            ->updateOrCreate(['role' => 'router'], ['status' => 'active', 'cluster_id' => $cluster->id]);
    }
    exit(0);
}

if ($argv[1] === 'fires') {
    $begun = microtime(true);
    $started = null;
    $subscriber = app(SwampRunSubscriber::class);
    $fire = null;
    do {
        $subscriber->pass();
        while (($job = app('queue')->connection(GatewayQueue::Connection)->pop(GatewayQueue::Operations->value)) !== null) {
            try {
                $job->fire();
            } catch (Throwable) {
                $job->release(10);
            }
        }
        $fires = Operation::query()->where('kind', 'schedule.run')->where('triggered_by', 'schedule')->get()
            ->filter(static fn (Operation $operation): bool => ($operation->input['id'] ?? null) === $input['schedule']);
        // A fire is a task from its start; note one seen running, then wait for the first one to end.
        $started = $started ?? ($fires->contains(static fn (Operation $operation): bool => ! $operation->status->terminal()) ? microtime(true) : null);
        $fire = $fires->first(static fn (Operation $operation): bool => $operation->status->terminal());
        if ($fire !== null) {
            break;
        }
        sleep(2);
    } while (microtime(true) < $begun + (int) $input['seconds']);
    $subscriber->close();
    echo json_encode(['fire' => $fire === null ? null : ['id' => $fire->id, 'status' => $fire->status->value, 'result' => $fire->result,
        'run_id' => $fire->state['swamp_fire']['run_id'] ?? null], 'seen_running' => $started !== null,
        'seconds' => round(microtime(true) - $begun, 2)]), PHP_EOL;
    exit($fire === null ? 2 : 0);
}

$started = microtime(true);
$server = ['REMOTE_ADDR' => $input['caller'], 'HTTPS' => 'on', 'HTTP_HOST' => 'gateway.orbit',
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $input['key']];
if (isset($input['horizon'])) {
    // Horizon has no HTTP command: the release runner calls this production admission action.
    config(['orbit.gateway_queue.connection' => 'proof-horizon']);
    $submitted = app(App\Infrastructure\Gateway\GatewayHorizon::class)->converge(
        Node::query()->findOrFail($input['horizon']['node']),
        fn (): App\Models\Process => App\Models\Process::query()->findOrFail($input['horizon']['scheduler']),
    );
    $response = new Illuminate\Http\JsonResponse([], 202);
    $body = ['data' => ['id' => $submitted['operation_id']]];
} elseif (isset($input['artisan'])) {
    // An operator command on the Gateway host, such as orbit:node-agent-rekey; it names its Operation in its output.
    $code = Artisan::call($input['artisan'], $input['arguments'] ?? []);
    $named = preg_match('/Operation #([1-9][0-9]*)/', Artisan::output(), $match) === 1 ? (int) $match[1] : null;
    $response = new Illuminate\Http\JsonResponse(['output' => Artisan::output()], $code === 0 && $named !== null ? 202 : 422);
    $body = ['data' => ['id' => $named]];
} else {
    $request = Request::create('https://gateway.orbit/api/v1/'.$input['path'], $input['method'], server: $server,
        content: json_encode($input['body'] ?? new stdClass, JSON_THROW_ON_ERROR));
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $body = json_decode((string) $response->getContent(), true);
}
// A record request (a Project, a setup step) starts no Operation; its reply is the result.
if (($input['operation'] ?? true) === false) {
    echo json_encode(['http_status' => $response->getStatusCode(), 'response' => $body]), PHP_EOL;
    exit($response->isSuccessful() ? 0 : 1);
}
$id = $body['data']['operation']['id'] ?? $body['data']['id'] ?? null;
$admitted = microtime(true);
if (! is_int($id)) {
    echo json_encode(['http_status' => $response->getStatusCode(), 'response' => $body]), PHP_EOL;
    exit(1);
}
$subscriber = app(SwampRunSubscriber::class);
$subscriber->recover();
$deadline = $started + (int) $input['seconds'];
// The last failure of each job class, so a receipt shows why a projection never finished.
$jobErrors = [];
do {
    $subscriber->follow($id);
    $subscriber->pass();
    // Horizon works the Gateway queue in production; projection runs on its operations queue. Jobs are fired
    // directly: the queue:work command start hook needs Documents state this VM does not have.
    while (($job = app('queue')->connection(GatewayQueue::Connection)->pop(GatewayQueue::Operations->value)) !== null) {
        try {
            $job->fire();
        } catch (Throwable $exception) {
            // As the queue worker does: a failed attempt is released and retried.
            $jobErrors[$job->resolveName()] = $exception::class.': '.mb_substr($exception->getMessage(), 0, 500);
            $job->release(10);
        }
    }
    $operation = Operation::query()->findOrFail($id);
    if ($operation->status->terminal()) {
        break;
    }
    sleep(2);
} while (microtime(true) < $deadline);
$subscriber->close();
echo json_encode(['http_status' => $response->getStatusCode(), 'operation' => [
    'id' => $operation->id, 'kind' => $operation->kind, 'target_id' => $operation->target_id, 'status' => $operation->status->value, 'error_code' => $operation->error_code,
    'input' => $operation->input, 'result' => $operation->result, 'swamp_run_id' => $operation->state['swamp_run_id'] ?? null,
], 'admit_seconds' => round($admitted - $started, 2), 'terminal_seconds' => round(microtime(true) - $started, 2), 'job_errors' => $jobErrors]), PHP_EOL;
exit($operation->status->terminal() ? 0 : 2);
