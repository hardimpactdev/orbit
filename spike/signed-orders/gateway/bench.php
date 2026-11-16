<?php
// SPIKE(signed-orders): 60 signed orders through Reverb, the observer and the executor, then the same wake
// over SSH with a shared and a fresh connection. Run from apps/gateway as orbit. Prints JSON lines only.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/opt/orbit-spike/order.php';

$nodeId = 3;
$orders = (int) ($argv[1] ?? 60);
$sshRuns = (int) ($argv[2] ?? 30);
$bound = 0.300;
$node = App\Models\Node::query()->findOrFail($nodeId);
$key = app(App\Infrastructure\Ssh\SshKeyProvider::class)->privateKeyPath();
$known = '/tmp/orbit-spike-known-hosts';
$control = '/tmp/orbit-spike-ssh-%C';
$sudo = $node->user === 'root' ? '' : 'sudo -n ';
$remote = escapeshellarg("{$sudo}systemctl start orbit-spike-wake@102.service && cat /run/orbit-spike/started-102");

function ssh_wake(string $base, string $remote): array
{
    $t0 = microtime(true);
    $out = shell_exec("$base $remote 2>/dev/null");
    $t1 = microtime(true);

    return [$t0, $t1, trim((string) $out)];
}

$base = sprintf('ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s -i %s %s@%s',
    $known, escapeshellarg($key), escapeshellarg($node->user), escapeshellarg($node->wireguard_ip));
$shared = "$base -o ControlMaster=auto -o ControlPath=$control -o ControlPersist=120";
$fresh = "$base -o ControlMaster=no -o ControlPath=none";

// Warm the WebSocket path and the HTTP client once; these are not counted.
for ($i = 0; $i < 3; $i++) {
    $o = spike_order($nodeId, '101', "warm-$i");
    spike_broadcast($nodeId, $o['envelope']);
    spike_result(json_decode($o['payload'], true)['nonce'], 1.0);
}
spike_redis()->del('spike:results');

for ($i = 0; $i < $orders; $i++) {
    $ts = microtime(true);
    $o = spike_order($nodeId, '101', "op-$i");
    $nonce = json_decode($o['payload'], true)['nonce'];
    $t0 = microtime(true);
    spike_broadcast($nodeId, $o['envelope']);
    $tb = microtime(true);
    $r = spike_result($nonce, $bound - ($tb - $t0));
    $tr = microtime(true);
    $row = ['kind' => 'order', 'i' => $i, 'sign_ms' => ($t0 - $ts) * 1000, 'broadcast_call_ms' => ($tb - $t0) * 1000];
    if ($r && ($r['body']['status'] ?? '') === 'done') {
        $row += ['miss' => false, 'unit_ms' => ((int) $r['body']['unit_started_ns'] / 1e6) - $t0 * 1000,
            'result_route_ms' => $r['received_us'] / 1000 - $t0 * 1000,
            'observer_ms' => (int) $r['observer_received_ns'] / 1e6 - $t0 * 1000, 'observer_replied_ms' => (int) $r['observer_replied_ns'] / 1e6 - $t0 * 1000,
            'exec_ms' => (int) $r['body']['exec_ns'] / 1e6 - $t0 * 1000, 'verified_ms' => (int) $r['body']['verified_ns'] / 1e6 - $t0 * 1000, 'result_seen_ms' => ($tr - $t0) * 1000];
    } else {
        // Today's path after the bound.
        [$s0, $s1, $out] = ssh_wake($shared, $remote);
        $row += ['miss' => true, 'status' => $r['body']['status'] ?? null, 'fallback_done_ms' => ($s1 - $t0) * 1000, 'fallback_ok' => ctype_digit($out)];
    }
    echo json_encode($row), "\n";
    usleep(200_000);
}

foreach (['ssh_shared' => $shared, 'ssh_fresh' => $fresh] as $kind => $cmd) {
    if ($kind === 'ssh_shared') {
        ssh_wake($cmd, $remote); // opens the master
    }
    for ($i = 0; $i < $sshRuns; $i++) {
        [$t0, $t1, $out] = ssh_wake($cmd, $remote);
        echo json_encode(['kind' => $kind, 'i' => $i, 'ok' => ctype_digit($out),
            'unit_ms' => ctype_digit($out) ? (int) $out / 1e6 - $t0 * 1000 : null, 'done_ms' => ($t1 - $t0) * 1000]), "\n";
        usleep(100_000);
    }
}
shell_exec("$shared -O exit 2>/dev/null");
@unlink($known);
