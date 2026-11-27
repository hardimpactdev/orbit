<?php

declare(strict_types=1);

use App\Domain\Engine\NodeBusAccess;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\NodeExecutorInput;
use App\Infrastructure\Engine\GatewayBus;
use App\Infrastructure\Engine\EngineBus;
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
        'executor_guard_gateway' => NodeBusAccess::guard('orbit'),
        'executor_bus' => json_encode(['url' => NodeBusAccess::url()], JSON_THROW_ON_ERROR),
        'routes' => config('orbit.engine.kinds'),
    ];
} elseif ($mode === 'capabilities' && ctype_digit($argv[2] ?? '')) {
    $node = (int) $argv[2];
    $report = $request('$JS.API.DIRECT.GET.KV_NODES.$KV.NODES.n'.$node);
    $result = ['version' => $report['version'] ?? null, 'actions' => $report['actions'] ?? [], 'features' => $report['features'] ?? []];
} elseif ($mode === 'report-without-feature' && ctype_digit($argv[2] ?? '') && preg_match('/\A[a-z0-9_]+\.[a-z0-9_]+\z/', $argv[3] ?? '') === 1) {
    // Fixture only: the Node's own report with its actions intact and one optional input (feature) left out, as an older
    // executor would report it. The executor's next report, within 60 s or at its restart, puts the true one back.
    $node = (int) $argv[2];
    $report = $request('$JS.API.DIRECT.GET.KV_NODES.$KV.NODES.n'.$node);
    $report['features'] = array_values(array_diff($report['features'] ?? [], [$argv[3]]));
    $request('$KV.NODES.n'.$node, json_encode($report, JSON_THROW_ON_ERROR));
    $read = $request('$JS.API.DIRECT.GET.KV_NODES.$KV.NODES.n'.$node);
    $result = ['actions' => $read['actions'] ?? [], 'features' => $read['features'] ?? []];
} elseif ($mode === 'extension' && in_array($argv[2] ?? '', ['tasks', 'conn'], true) && in_array($argv[3] ?? '', ['read', 'on', 'off'], true)) {
    // Fixture only: the nested fleet has no AI router (Valkey and CLIProxyAPI), so the proof sets the stored state directly, past the
    // dependency check of extension:enable, and restores exactly what it read. Model routing is not under test.
    $store = $app->make(ExtensionStore::class);
    if ($argv[3] !== 'read') {
        $store->set($argv[2], $argv[3] === 'on');
    }
    $result = ['extension' => $argv[2], 'enabled' => $store->enabled($argv[2])];
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
        // Until this step's own message is the latest on the subject, there is nothing to read yet: the caller waits.
        if ($message['subject'] !== $subject || $line['operation'] !== $operation.':'.$step || $line['target'] !== $target
            || $line['action'] !== $action || ($isOrder && $line['node'] !== $node)) {
            echo "null\n";
            exit;
        }
        if (! $isOrder && ! in_array($line['status'], ['done', 'applied', 'output'], true)) {
            throw new RuntimeException('The queued receipt of this proof step is not a success.');
        }
        if (! $isOrder && ($request('$JS.API.STREAM.MSG.DELETE.RECEIPTS', ['seq' => $message['seq']])['success'] ?? false) !== true) {
            throw new RuntimeException('The proof receipt was not dropped.');
        }
        $result = ['sequence' => $message['seq'], 'subject' => $subject,
            'line' => array_intersect_key($line, array_flip(['operation', 'target', 'action', 'status', 'expires_ms']))];
    }
} elseif ($mode === 'watch-reads' && ctype_digit($argv[2] ?? '') && ctype_digit($argv[3] ?? '')) {
    // The signed reads to one Node's executor and their replies, as the bus carries them, one JSON line each as it comes: each
    // order's nonce, expiry and Node budget, and each reply's status and work state. It answers nothing: the executor's own replies
    // are the ones observed.
    [$node, $seconds] = [(int) $argv[2], (int) $argv[3]];
    $events = [];
    $inboxes = [];
    $now = static fn (): int => (int) floor(microtime(true) * 1000);
    $watcher = EngineBus::connect(reconnect: false);
    $watcher->subscribe("orbit.node.{$node}.reads", static function ($payload, $replyTo) use (&$events, &$inboxes, $now): void {
        $fields = json_decode((string) base64_decode(explode('.', $payload->body, 2)[0], true), true) ?: [];
        $inboxes[(string) $replyTo] = count($inboxes) + 1;
        echo json_encode($events[] = ['event' => 'order', 'at_ms' => $now(), 'send' => $inboxes[(string) $replyTo], 'nonce' => $fields['nonce'] ?? null,
            'action' => $fields['action'] ?? null, 'target' => $fields['target'] ?? null, 'expires_ms' => $fields['expires_ms'] ?? null,
            'budget_ms' => $fields['input']['budget_ms'] ?? null], JSON_THROW_ON_ERROR), "\n";
    });
    $watcher->subscribe('_INBOX.>', static function ($payload) use (&$events, &$inboxes, $now): void {
        if (! isset($inboxes[$payload->subject])) {
            return;
        }
        $line = json_decode($payload->body, true) ?: [];
        $output = is_string($line['stdout'] ?? null) ? (json_decode($line['stdout'], true) ?: []) : [];
        echo json_encode($events[] = ['event' => 'reply', 'at_ms' => $now(), 'send' => $inboxes[$payload->subject], 'status' => $line['status'] ?? null,
            'code' => $line['code'] ?? $line['reason'] ?? null, 'state' => $output['state'] ?? null, 'reason' => $output['reason'] ?? null],
            JSON_THROW_ON_ERROR), "\n";
    });
    $watcher->ping();
    echo "ready\n";
    $until = microtime(true) + $seconds;
    while (($left = $until - microtime(true)) > 0) {
        $watcher->process(min(0.2, $left), reply: false);
    }
    $result = ['events' => count($events)];
} else {
    throw new RuntimeException('Unknown clone proof guest action.');
}

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
