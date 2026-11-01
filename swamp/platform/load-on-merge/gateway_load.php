<?php

/* POLICY_PROOF */

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$app = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$receipt = json_decode(base64_decode('LOAD_RECEIPT'), true, 512, JSON_THROW_ON_ERROR);
try {
    $out = app(App\Infrastructure\GatewayReleases\GatewayReleaseLock::class)->run(function () use ($receipt): array {
        $status = app(App\Actions\GatewayReleases\ShowGatewayReleaseAutomationAction::class)->execute()->toArray();
        if (!is_string($status['current_sha'] ?? null) || strlen($status['current_sha']) !== 40) {
            throw new RuntimeException('gateway_status_unproved');
        }
        $active = App\Models\GatewayRelease::query()->whereIn('outcome', ['queued', 'running'])->count();
        if ($active !== 0) {
            throw new RuntimeException('gateway_release_active');
        }
        $receipt['gateway_release_lock'] = 'GatewayReleaseLock::run';
        $receipt['gateway_auto_status_checked'] = true;
        $receipt['gateway_active_release_count'] = $active;
        $receipt['gateway_current_sha'] = $status['current_sha'];
        $node = App\Models\Node::query()->findOrFail(209);
        $connection = new App\Infrastructure\Ssh\SshConnection(
            host: $node->wireguard_ip, user: $node->user, port: 22,
            identityFile: app(App\Infrastructure\Ssh\SshKeyProvider::class)->privateKeyPath(),
            knownHostsFile: app(App\Infrastructure\Ssh\KnownHostsStore::class)->path(), commandTimeout: 960,
        );
        $executor = app(App\Infrastructure\Ssh\SshExecutor::class);
        $command = new App\Infrastructure\Ssh\RemoteCommand(
            ['sudo', '-n', '/usr/bin/python3', '-B', '/usr/local/libexec/orbit-swamp-main-load.py', '--gateway-interlocked'],
            input: json_encode($receipt, JSON_THROW_ON_ERROR), maxOutputBytes: 2097152, timeout: 960,
        );
        $result = $executor->execute($connection, $command);
        if (!$result->succeeded()) {
            throw new RuntimeException('host_load_uncertain_inspect_checkpoint');
        }
        $publication = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        if (($publication['state'] ?? null) !== 'loaded_grants_pending') {
            return $publication;
        }
        $socket = app(App\Infrastructure\AgentView\WebSocketClient::class);
        $deadline = microtime(true) + 240;
        $rpc = static function (string $type, array $payload) use ($socket, $deadline): array {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('configured_token_budget_exceeded');
            }
            $id = bin2hex(random_bytes(8));
            $socket->send(['type' => $type, 'id' => $id, 'payload' => (object) $payload]);
            $until = min($deadline, microtime(true) + 8);
            while ($socket->isConnected() && ($left = $until - microtime(true)) > 0) {
                foreach ($socket->receive(min(.2, $left)) as $message) {
                    if (($message['id'] ?? null) === $id) {
                        if (($message['type'] ?? null) !== $type) {
                            throw new RuntimeException('configured_token_metadata_refused');
                        }
                        return $message['payload']['data'] ?? $message['payload'];
                    }
                }
            }
            throw new RuntimeException('configured_token_metadata_timeout');
        };
        try {
            $socket->connect(new App\Infrastructure\AgentView\WebSocketEndpoint(
                config('swamp.address'), 443, 'swamp.orbit', '/', config('swamp.ca_path'), config('swamp.run_token'),
            ), 15);
            $policy = $publication['manifest_grants'];
            $policy['configured_gateway_token_proof'] = proveConfiguredManifestPolicy($rpc, $policy);
        } finally {
            $socket->close();
        }
        $finalize = <<<'PYTHON'
import importlib.util,json,sys
s=importlib.util.spec_from_file_location('L','/usr/local/libexec/orbit-swamp-main-load.py')
L=importlib.util.module_from_spec(s);s.loader.exec_module(L)
print(json.dumps(L.finalize_manifest_grants(json.load(sys.stdin))))
PYTHON;
        $command = new App\Infrastructure\Ssh\RemoteCommand(
            ['sudo', '-n', '/usr/bin/python3', '-B', '-c', $finalize],
            input: json_encode($policy, JSON_THROW_ON_ERROR), maxOutputBytes: 2097152, timeout: 60,
        );
        $result = $executor->execute($connection, $command);
        if (!$result->succeeded()) {
            throw new RuntimeException('acceptance_uncertain_inspect_checkpoint');
        }
        return json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
    });
    $out['gateway_release_lock_exited_at'] = gmdate('c');
    echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Transport and native diagnostics can contain protected configuration.
    fwrite(STDERR, "gateway_interlocked_load_refused_or_uncertain_inspect_checkpoints\n");
    exit(1);
}
