<?php

declare(strict_types=1);

/*
 * Minimal proof Gateway driver, run in apps/gateway of the staged commit.
 *   seed     stdin {gateway, operator, target}: fixture Node records and access, nothing else.
 *   request  stdin {caller, method, path, body, key, seconds}: one real API request through the HTTP
 *            kernel from the caller's address, then the production SwampRunSubscriber follows the
 *            admitted Operation to its native terminal projection. Reverb, which drives the
 *            subscriber in production, is not part of the minimal topology.
 */

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

function node(string $name, string $address, string $hostKey, array $roles = [], ?int $id = null): Node
{
    $bytes = base64_decode($hostKey, true);
    $node = (new Node)->forceFill([...($id === null ? [] : ['id' => $id]),
        'name' => $name, 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
        'public_ssh_host' => $address, 'public_ssh_port' => 22, 'wireguard_ip' => $address,
        'wireguard_public_key' => base64_encode(random_bytes(32)), 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $hostKey,
        'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, true)), '='), 'status' => 'active',
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
    // Only the target is reached over SSH; the other two need a well-formed key and no host.
    $unused = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32));
    // Records first, then one known-hosts publication with the complete pin snapshot, as after enrollment.
    [$gateway, $operator, $target] = Node::withoutEvents(fn (): array => [
        // The Gateway hard-codes Node 209 as the Swamp Node; here this VM carries gateway and swamp together (F14).
        node('gateway', $input['gateway'], $input['gateway_host_key'], ['gateway', 'swamp'], 209),
        node('operator', $input['operator'], $unused),
        node($input['target']['key'], $input['target']['address'], $input['target']['host_key']),
    ]);
    app(GatewayServingHost::class)->remember($gateway);
    $operator->accessibleNodes()->attach([$gateway->id, $target->id]);
    app(WorkerKnownHostsPublisher::class)->publish();
    echo json_encode(['nodes' => ['gateway' => $gateway->id, 'operator' => $operator->id, 'target' => $target->id]]), PHP_EOL;
    exit(0);
}

$started = microtime(true);
$server = ['REMOTE_ADDR' => $input['caller'], 'HTTPS' => 'on', 'HTTP_HOST' => 'gateway.orbit',
    'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $input['key']];
$request = Request::create('https://gateway.orbit/api/v1/'.$input['path'], $input['method'], server: $server,
    content: json_encode($input['body'] ?? new stdClass, JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$body = json_decode((string) $response->getContent(), true);
$id = $body['data']['operation']['id'] ?? $body['data']['id'] ?? null;
$admitted = microtime(true);
if (! is_int($id)) {
    echo json_encode(['http_status' => $response->getStatusCode(), 'response' => $body]), PHP_EOL;
    exit(1);
}
$subscriber = app(SwampRunSubscriber::class);
$subscriber->recover();
$deadline = $started + (int) $input['seconds'];
do {
    $subscriber->follow($id);
    $subscriber->pass();
    // Horizon works the Gateway queue in production; projection runs on its operations queue. Jobs are fired
    // directly: the queue:work command start hook needs Documents state this VM does not have.
    while (($job = app('queue')->connection(GatewayQueue::Connection)->pop(GatewayQueue::Operations->value)) !== null) {
        try {
            $job->fire();
        } catch (Throwable) {
            // As the queue worker does: a failed attempt is released and retried.
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
    'id' => $operation->id, 'kind' => $operation->kind, 'status' => $operation->status->value, 'error_code' => $operation->error_code,
    'input' => $operation->input, 'result' => $operation->result, 'swamp_run_id' => $operation->state['swamp_run_id'] ?? null,
], 'admit_seconds' => round($admitted - $started, 2), 'terminal_seconds' => round(microtime(true) - $started, 2)]), PHP_EOL;
exit($operation->status->terminal() ? 0 : 2);
