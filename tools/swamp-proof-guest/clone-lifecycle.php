<?php

declare(strict_types=1);

use App\Domain\Engine\NodeBusAccess;
use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\NodeExecutorInput;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Engine\GatewayBus;
use App\Infrastructure\Engine\EngineBus;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;

chdir(__DIR__.'/../../apps/gateway');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (GatewayBus::address() !== '10.45.0.1' || ! is_file('/run/orbit-proof-clones/gateway-env')) {
    throw new RuntimeException('The clone fixture needs its isolated nested Gateway.');
}

$mode = $argv[1] ?? '';
$bus = $app->make(EngineBus::class);
$request = static function (string $subject, array|string $payload = '') use ($bus): array {
    $reply = $bus->client()->dispatch($subject, $payload);

    return (string) $reply === '' ? [] : json_decode((string) $reply, true, flags: JSON_THROW_ON_ERROR);
};

if ($mode === 'configuration') {
    $runtime = $app->make(GatewayRuntimeFiles::class)->render();
    $files = array_column($runtime['files'], 'content', 'path');
    $units = array_column($runtime['units'], 'unit', 'name');
    $result = [
        'nats' => $files[GatewayBus::ConfigPath],
        'nats_artifact' => GatewayBus::artifact(),
        'nats_unit' => $units['nats-server.service'],
        'engine_unit' => $units['orbit-gateway-engine.service'],
        'executor_unit' => NodeExecutorInput::unit(true),
        'executor_keys' => implode("\n", config('orbit.executor.public_keys'))."\n",
        'executor_guard' => NodeBusAccess::guard(),
        'executor_bus' => json_encode(['url' => NodeBusAccess::url()], JSON_THROW_ON_ERROR),
        'routes' => config('orbit.engine.kinds'),
    ];
} elseif ($mode === 'capabilities' && ctype_digit($argv[2] ?? '')) {
    $node = (int) $argv[2];
    $report = $request('$JS.API.DIRECT.GET.KV_NODES.$KV.NODES.n'.$node);
    $result = ['version' => $report['version'] ?? null, 'actions' => $report['actions'] ?? []];
} elseif ($mode === 'task-copy' && count($argv) === 5 && ctype_digit($argv[2]) && ctype_digit($argv[3]) && preg_match('/\A[0-9a-f]{40}\z/', $argv[4]) === 1) {
    // Makes a ready disposable clone the running copy of a new external group, as task:start leaves it.
    $app->make(TaskExtensionState::class)->enable();
    $copy = Instance::query()->findOrFail((int) $argv[2]);
    if ($copy->project_id !== (int) $argv[3] || $copy->task_workspace_owner_id !== null) {
        throw new RuntimeException('Only an unowned clone of the proof project becomes a task copy.');
    }
    $group = Task::query()->create(['project_id' => $copy->project_id, 'title' => 'Sync proof', 'brief' => 'Sync proof', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external']);
    $copy->forceFill(['branch' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'starting_commit' => $argv[4]])->save();
    $group->update(['taskable_type' => $copy->getMorphClass(), 'taskable_id' => $copy->id]);
    $result = ['group' => $group->id, 'branch' => 'task-'.$group->id];
} elseif (in_array($mode, ['pending-order', 'drop-receipt'], true) && in_array(count($argv), [5, 7], true)
    && ctype_digit($argv[2]) && ctype_digit($argv[3]) && preg_match('/\A[0-9][0-9a-z:-]*\z/', $argv[4]) === 1) {
    [$node, $operation, $target] = array_slice($argv, 2, 3);
    [$step, $action] = [$argv[5] ?? 'remove', $argv[6] ?? 'instance.destroy'];
    $isOrder = $mode === 'pending-order';
    $subject = $isOrder ? 'orbit.node.'.$node.'.orders' : 'orbit.receipts.'.$node;
    $stream = $isOrder ? 'ORDERS' : 'RECEIPTS';
    $message = $request('$JS.API.STREAM.MSG.GET.'.$stream, ['last_by_subj' => $subject])['message'] ?? null;
    $result = null;
    if ($message !== null) {
        $data = base64_decode($message['data'], true);
        if ($isOrder) {
            [$payload, $signature] = array_map(static fn (string $part): string|false => base64_decode($part, true), explode('.', $data, 2));
            if (! array_any(config('orbit.executor.public_keys'), static fn (string $key): bool => sodium_crypto_sign_verify_detached($signature, $payload, base64_decode($key, true)))) {
                throw new RuntimeException('The queued proof order is not signed by the configured Gateway.');
            }
            $line = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } else {
            $line = json_decode($data, true, flags: JSON_THROW_ON_ERROR)['line'];
        }
        if ($message['subject'] !== $subject || $line['operation'] !== $operation.':'.$step || $line['target'] !== $target
            || $line['action'] !== $action || ($isOrder ? $line['node'] !== $node : $line['status'] !== 'done')) {
            throw new RuntimeException('The queued message does not belong to this proof step.');
        }
        if (! $isOrder && ($request('$JS.API.STREAM.MSG.DELETE.RECEIPTS', ['seq' => $message['seq']])['success'] ?? false) !== true) {
            throw new RuntimeException('The proof receipt was not dropped.');
        }
        $result = ['sequence' => $message['seq'], 'subject' => $subject,
            'line' => array_intersect_key($line, array_flip(['operation', 'target', 'action', 'status', 'expires_ms']))];
    }
} else {
    throw new RuntimeException('Unknown clone proof guest action.');
}

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
