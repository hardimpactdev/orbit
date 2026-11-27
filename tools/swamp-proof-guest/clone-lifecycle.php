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
    $result = ['version' => $report['version'] ?? null, 'actions' => $report['actions'] ?? []];
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
} else {
    throw new RuntimeException('Unknown clone proof guest action.');
}

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
