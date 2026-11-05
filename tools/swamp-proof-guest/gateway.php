<?php

declare(strict_types=1);

/*
 * Minimal proof Gateway driver, run in apps/gateway of the staged commit.
 *   seed     stdin {gateway, gateway_agent_secret_hash, operator, targets, subnet, wireguard?}: fixture Node records and access, and the
 *            swamp.orbit Route an operator records before the first swamp role (version 17).
 *   known-hosts the worker known-hosts publication, once swamp.orbit answers through Caddy (version 17).
 *   agent    stdin {node}: the Node agent's configuration, certificate and unit as node:converge renders them.
 *   request  stdin {caller, method, path, body, key, seconds}: one real API request through the HTTP
 *            kernel from the caller's address, then the production SwampRunSubscriber follows the
 *            admitted Operation to its native terminal projection. Reverb, which drives the
 *            subscriber in production, is not part of the minimal topology.
 *   fires    stdin {schedule, seconds}: the same subscriber, with its shared run reader, until a calendar
 *            fire of that Schedule is recorded as a task.
 *   caddyfile stdin {node}: the Node's complete Caddyfile from the Gateway's own Caddy build renderer.
 *   fixture  stdin {roles: {node_id: [role]}, replace_roles: {node_id: [role]}, wireguard_keys: {node_id: public key}, wireguard_members: {node_id: {ip, public_key}}, vpn_subnet?, vpn_endpoint?, projects: [attributes],
 *            extensions: [name], task_groups: [attributes + subtasks: [attributes] + taskable?: {instance: id}],
 *            instances: [attributes], deployments: [attributes], task_vms: [attributes], operations: [attributes],
 *            clusters: [{name, tld?, node_ids, router_node_id}], routes: [{id, attributes}],
 *            instance_updates: [{id, attributes}], node_updates: [{id, attributes}], websocket?: node_id, analytics_tracking: [{instance_id, domain, timezone?}],
 *            gateway_settings: [{key, value, secret?}], task_vm_image_builds: [attributes, with id to change a recorded build]}: the records a kind fixture
 *            needs beyond the seed, such as the VPN hub, peers with keys and the VPN subnet, a Project under the fixed
 *            id its Swamp configuration names, an extension, or a task group's owned workspace (the group and its
 *            subtasks, its Instance, its task VM and its provision Operation) for kinds that run in it.
 */

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Infrastructure\WebSocket\NativeWebSocketCredentialManager;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;
use App\Infrastructure\WebSocket\WebSocketServiceUnitRenderer;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

function node(string $name, string $address, string $hostKey, array $roles = [], ?int $id = null, array $settings = [], ?string $wireguardIp = null, ?string $wireguardKey = null): Node
{
    $bytes = base64_decode($hostKey, true);
    $node = (new Node)->forceFill([...($id === null ? [] : ['id' => $id]),
        'name' => $name, 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
        'public_ssh_host' => $address, 'public_ssh_port' => 22, 'wireguard_ip' => $wireguardIp ?? $address,
        'wireguard_public_key' => $wireguardKey ?? base64_encode(random_bytes(32)), 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $hostKey,
        'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, true)), '='), 'status' => 'active',
        ...($settings === [] ? [] : ['settings' => $settings]),
    ]);
    $node->save();
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }

    return $node;
}

// Non-secret reset baseline: real observer facts and the records cases may mutate.
if ($argv[1] === 'proof-state') {
    $facts = \App\Models\NodeFact::query()->orderBy('node_id')->orderBy('kind')->get()
        ->map(static fn (\App\Models\NodeFact $fact): array => [
            'node_id' => $fact->node_id, 'kind' => $fact->kind->value,
            'received_at' => $fact->received_at->toIso8601String(), 'digest' => $fact->digest,
            'enabled' => $fact->kind->value === 'ufw' ? ($fact->data['enabled'] ?? false) : null,
        ])->all();
    $rules = Node::query()->where('name', '!=', 'operator')->get()->mapWithKeys(
        static fn (Node $node): array => [$node->name => app(\App\Domain\Firewall\FirewallInspectionInput::class)->expected($node)],
    )->all();
    $catalog = app(\App\Infrastructure\Firewall\NodeFirewallRuleCatalog::class);
    $commands = [];
    foreach (Node::query()->where('name', '!=', 'operator')->get() as $node) {
        $managed = $catalog->desiredBaseline($node);
        foreach ($node->roles()->get() as $assignment) {
            array_push($managed, ...$catalog->forRole($node, $assignment->role));
        }
        $commands[$node->name] = array_map(static fn ($rule): array => $rule->arguments, $managed);
    }
    // The presence heartbeat the agent view subscriber receives: the Gateway's own answer to "is this agent online".
    $agents = Node::query()->where('name', '!=', 'operator')->get()->mapWithKeys(
        static fn (Node $node): array => [$node->id => app(\App\Domain\NodeFacts\NodeAgentLiveness::class)->for($node)?->state->value],
    )->all();
    echo json_encode(['facts' => $facts, 'agents' => $agents, 'expected_firewall' => $rules, 'baseline_commands' => $commands,
        'firewall_records' => \App\Models\FirewallRule::query()->orderBy('id')->get()->toArray()]), PHP_EOL;
    exit(0);
}

if ($argv[1] === 'seed') {
    if (Node::query()->exists()) {
        throw new RuntimeException('proof.seed_database_not_empty');
    }
    // Only the targets and this VM are reached over SSH. node() records a well-formed key, which the seed then clears for the operator.
    $unused = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32));
    // Records first, then one known-hosts publication with the complete pin snapshot, as after enrollment.
    // With `wireguard`, every Node is a member of the topology's own WireGuard network (hub on the Gateway): the Gateway
    // knows a caller by its WireGuard address and key, and SSH stays on the Incus address. The operator is the outer VM,
    // a roleless caller-only member with no SSH identity (B2). Without it, the legacy bridge addresses stand in.
    $wireguard = $input['wireguard'] ?? null;
    $member = static fn (string $key): array => $wireguard === null ? [null, null]
        : [$wireguard['nodes'][$key]['ip'], $wireguard['nodes'][$key]['public_key']];
    // Records first, then one known-hosts publication with the complete pin snapshot, as after enrollment.
    [$gateway, $operator, $targets] = Node::withoutEvents(fn (): array => [
        // The Gateway hard-codes Node 209 as the Swamp Node; here this VM carries gateway and swamp together (F14).
        node('gateway', $input['gateway'], $input['gateway_host_key'], ['gateway', 'swamp'], 209, wireguardIp: $member('gateway')[0], wireguardKey: $member('gateway')[1]),
        node('operator', $input['operator'], $unused, wireguardIp: $member('operator')[0], wireguardKey: $member('operator')[1]),
        array_map(static fn (array $target): Node => node($target['key'], $target['address'], $target['host_key'],
            $target['roles'] ?? [], settings: $target['settings'] ?? [], wireguardIp: $member($target['key'])[0], wireguardKey: $member($target['key'])[1]), $input['targets']),
    ]);
    // The operator is a caller-only peer: an active WireGuard address with access, but no SSH identity, so nothing
    // treats it as a managed Node or reaches it over SSH (version 12).
    $operator->forceFill(['ssh_host_key_type' => null, 'ssh_host_key' => null, 'ssh_host_fingerprint' => null])->saveQuietly();
    // The verifier of the agent key up.py wrote on each target, as after enrollment.
    foreach ($targets as $index => $target) {
        $target->forceFill(['agent_secret_hash' => $input['targets'][$index]['agent_secret_hash'], ...($wireguard === null ? ['wireguard_public_key' => null] : [])])->saveQuietly();
    }
    // This VM runs a second Node agent for Node 209 beside the task VM's own observer, so Doctor reads 209's facts (version 17).
    $gateway->forceFill(['agent_secret_hash' => $input['gateway_agent_secret_hash']])->saveQuietly();
    // swamp.orbit as the operator records it on 209: a private custom proxy Route to serve on loopback (ADR 0243, version 17).
    $swamp = Route::query()->forceCreate(['kind' => RouteKind::CustomProxy, 'node_id' => $gateway->id, 'domain' => 'swamp.orbit',
        'provenance' => RouteProvenance::Explicit, 'publication' => 'private', 'status' => RouteStatus::Pending]);
    $swamp->customProxy()->create(['node_id' => $gateway->id, 'upstream' => 'http://127.0.0.1:9418']);
    // A Route is recorded pending and becomes active once its site is published.
    $swamp->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    app(GatewayServingHost::class)->remember($gateway);
    // The private network is this topology's, so Caddy listeners and peer checks use it (version 12).
    app(VpnSettings::class)->configure(subnet: $input['subnet'], endpoint: $wireguard['endpoint'] ?? null);
    $operator->accessibleNodes()->attach([$gateway->id, ...array_map(static fn (Node $target): int => $target->id, $targets)]);
    $nodes = ['gateway' => $gateway->id, 'operator' => $operator->id];
    foreach ($targets as $target) {
        $nodes[$target->name] = $target->id;
    }
    $firewall = app(NodeFirewallRuleCatalog::class);
    $gatewayRules = [...$firewall->desiredBaseline($gateway), ...$firewall->forRole($gateway, App\Domain\Nodes\RoleName::Gateway)];
    echo json_encode(['nodes' => $nodes, 'swamp_route' => $swamp->id,
        'gateway_firewall' => array_map(static fn ($rule): array => $rule->arguments, $gatewayRules)]), PHP_EOL;
    exit(0);
}

// The known-hosts publication starts a Swamp run, so it waits until swamp.orbit answers through Caddy.
if ($argv[1] === 'known-hosts') {
    app(WorkerKnownHostsPublisher::class)->publish();
    exit(0);
}

// The Node agent's files exactly as node:converge renders them (NodeAgentSshExecutor), for 209's own agent.
if ($argv[1] === 'agent') {
    $rendered = app(NodeAgentSshExecutor::class)->configurationForRoot(Node::query()->findOrFail((int) $input['node']), null);
    echo json_encode(['configuration' => $rendered['configuration'], 'certificate' => $rendered['certificate'], 'unit' => $rendered['unit']]), PHP_EOL;
    exit(0);
}

// The Node's complete Caddyfile as its Caddy build renders it, so up.py serves what every later build serves (version 12).
if ($argv[1] === 'caddyfile') {
    $caddyfile = app(NodeCaddyfileRenderer::class)->render(Node::query()->findOrFail((int) $input['node']));
    echo json_encode(['content' => $caddyfile->content, 'problems' => $caddyfile->problems]), PHP_EOL;
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
    // A production Instance's deployment history, from which the Gateway knows the releases it retains.
    foreach ($input['deployments'] ?? [] as $deployment) {
        (new InstanceDeployment)->forceFill($deployment)->save();
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
    // A Node's address and key on the topology's WireGuard network, recorded quietly as the seed records them (B2).
    foreach ($input['wireguard_members'] ?? [] as $id => $member) {
        Node::query()->findOrFail((int) $id)->forceFill(['wireguard_ip' => $member['ip'], 'wireguard_public_key' => $member['public_key']])->saveQuietly();
    }
    foreach ($input['wireguard_keys'] ?? [] as $id => $key) {
        Node::query()->findOrFail((int) $id)->update(['wireguard_public_key' => $key]);
    }
    if (isset($input['vpn_subnet'])) {
        app(VpnSettings::class)->configure(subnet: $input['vpn_subnet'], endpoint: $input['vpn_endpoint'] ?? null);
    }
    // Attributes a prepared Route needs for a kind, such as a failed Route whose sites stay published.
    foreach ($input['routes'] ?? [] as $route) {
        Route::query()->findOrFail((int) $route['id'])->forceFill($route['attributes'])->save();
    }
    // The same for a prepared Instance, such as an old Route target that is no longer active.
    foreach ($input['instance_updates'] ?? [] as $instance) {
        Instance::query()->findOrFail((int) $instance['id'])->forceFill($instance['attributes'])->save();
    }
    // The same for a topology Node, such as the LAN address a Cluster Router needs.
    foreach ($input['node_updates'] ?? [] as $node) {
        Node::query()->findOrFail((int) $node['id'])->forceFill($node['attributes'])->save();
    }
    // Gateway-scope settings an extension's setup leaves, such as the ai-router origin and management key a task VM claim reads.
    foreach ($input['gateway_settings'] ?? [] as $setting) {
        app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), $setting['key'], $setting['value'],
            ($setting['secret'] ?? false) === true ? SettingValueProtection::Secret : SettingValueProtection::Plain);
    }
    // Task VM image builds as a finished build leaves them, such as a promoted base of an older recipe; an id changes a recorded build.
    foreach ($input['task_vm_image_builds'] ?? [] as $build) {
        (TaskVmImageBuild::query()->find($build['id'] ?? null) ?? new TaskVmImageBuild)->forceFill($build)->save();
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
    // Analytics tracking Routes of an Instance as a finished analytics.enable leaves them: an active Route of kind
    // analytics_tracking beside the Instance's authoritative Route, and the Plausible site binding (version 12).
    // The analytics upstream is an active `analytics` role, which `roles` records.
    foreach ($input['analytics_tracking'] ?? [] as $spec) {
        $instance = Instance::query()->findOrFail((int) $spec['instance_id']);
        $owner = $instance->authoritativeRoute() ?? throw new RuntimeException('proof.analytics_owner_missing');
        $route = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'domain' => $spec['domain'],
            'node_id' => $owner->node_id, 'cluster_id' => $owner->cluster_id, 'publication' => $owner->publication,
            'provenance' => RouteProvenance::Explicit, 'status' => RouteStatus::Pending]);
        $route->analyticsTracking()->create(['instance_id' => $instance->id]);
        // The insert trigger refuses an active Route; it becomes active once recorded, as enable's completion makes it.
        $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
        app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), 'analytics.site.'.$instance->id,
            json_encode(['domain' => $owner->domain, 'previous_domain' => null, 'timezone' => $spec['timezone'] ?? 'Europe/Amsterdam', 'goals' => []], JSON_THROW_ON_ERROR));
    }
    // The websocket role on one Node, as its convergence leaves it: the role, the server credentials and the
    // site marked serving. The Reverb .env goes to up.py, which runs the pinned server (version 11).
    if (isset($input['websocket'])) {
        $node = Node::query()->findOrFail((int) $input['websocket']);
        $node->roles()->firstOrCreate(['role' => 'websocket'], ['status' => 'active']);
        $credentials = app(NativeWebSocketCredentialManager::class)->ensure($node);
        app(WebSocketDnsTarget::class)->markServing($node->id);
        // up.py places the Reverb certificate where the role's certificate step puts it (version 12).
        app(CaddySiteCertificates::class)->record($node->id, WebSocketCaddySiteSource::CertificateSlug);
        echo json_encode(['websocket' => ['environment' => app(WebSocketEnvironmentRenderer::class)->render($credentials, (int) config('orbit.websocket.port')),
            'unit' => app(WebSocketServiceUnitRenderer::class)->render('/opt/orbit/websocket', 'orbit', 'orbit', (int) config('orbit.websocket.port')),
            'repository' => config('orbit.websocket.repository'), 'ref' => config('orbit.websocket.ref'), 'port' => (int) config('orbit.websocket.port')]]), PHP_EOL;
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
