<?php
// SPIKE(signed-orders) round 2: orders to the long-running executor. Run from apps/gateway as orbit.
//   php bench2.php seq <http|channel> <orders>     one order at a time on target 101
//   php bench2.php burst <http|channel> <rounds>   5 orders at once, one per target 101..105
//   php bench2.php ssh <runs>                      the same thaw and HTTP check over SSH, shared and fresh
//   php bench2.php same <http|channel>             2 Operations on one target, then 1 Operation twice, at once
//   php bench2.php repeat <http|channel>           one Operation, then the same Operation in a new order
// Between orders the test freezes the targets over SSH; the executor never freezes. Prints JSON lines.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/opt/orbit-spike/order.php';

$nodeId = 3;
$bound = 0.300;
$node = App\Models\Node::query()->findOrFail($nodeId);
$key = app(App\Infrastructure\Ssh\SshKeyProvider::class)->privateKeyPath();
$base = sprintf('ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/orbit-spike-known-hosts -i %s %s@%s',
    escapeshellarg($key), escapeshellarg($node->user), escapeshellarg($node->wireguard_ip));
$shared = "$base -o ControlMaster=auto -o ControlPath=/tmp/orbit-spike-ssh-%C -o ControlPersist=300";
$fresh = "$base -o ControlMaster=no -o ControlPath=none";

function freeze(string $ssh, array $targets): void
{
    shell_exec("$ssh ".escapeshellarg('sudo -n /usr/local/libexec/orbit-spike-freeze '.implode(' ', $targets)).' 2>&1');
}

function ms(?string $ns, float $t0): ?float
{
    return $ns === null || $ns === '' ? null : (int) $ns / 1e6 - $t0 * 1000;
}

/** Collects results for these nonces until all came or the time is up. */
function spike_collect(array $nonces, float $timeout): array
{
    $found = [];
    $deadline = microtime(true) + $timeout;
    while (count($found) < count($nonces) && ($left = $deadline - microtime(true)) > 0) {
        $item = spike_redis()->blPop(['spike:results'], max(0.01, $left));
        if (! $item) {
            break;
        }
        $r = json_decode($item[1], true);
        $nonce = $r['body']['nonce'] ?? null;
        if (in_array($nonce, $nonces, true)) {
            $r['seen_us'] = (int) (microtime(true) * 1e6);
            $found[$nonce] = $r;
        }
    }

    return $found;
}

function row(array $r, float $t0): array
{
    $b = $r['body'];

    return [
        'status' => $b['status'] ?? null, 'repeat' => $b['repeat'] ?? false, 'joined' => $b['joined'] ?? null,
        'recovered' => $b['recovered'] ?? null, 'lock_wait_ms' => $b['lock_wait_ms'] ?? null, 'operation' => $b['operation'] ?? null,
        'observer_ms' => ms($r['observer_received_ns'] ?? null, $t0), 'accepted_ms' => ms($b['accepted_ns'] ?? null, $t0),
        'verified_ms' => ms($b['verified_ns'] ?? null, $t0), 'action_ms' => ms($b['action_ns'] ?? null, $t0),
        'thawed_ms' => ms($b['thawed_ns'] ?? null, $t0), 'ready_ms' => ms($b['ready_ns'] ?? null, $t0),
        'observer_replied_ms' => ms($r['observer_replied_ns'] ?? null, $t0),
        'result_ms' => $r['received_us'] / 1000 - $t0 * 1000, 'seen_ms' => $r['seen_us'] / 1000 - $t0 * 1000,
    ];
}

function sshWake(string $ssh, string $target): string
{
    $port = 8000 + ((int) $target % 1000);

    return "$ssh ".escapeshellarg("echo 0 | sudo -n tee /sys/fs/cgroup/system.slice/orbit-spike-target-$target.service/cgroup.freeze >/dev/null && until curl -sf -o /dev/null --max-time 0.5 http://127.0.0.1:$port/; do sleep 0.002; done; date +%s%N");
}

$mode = $argv[1];
$reply = $argv[2] ?? 'http';
$run = bin2hex(random_bytes(3));
spike_redis()->del('spike:results');
freeze($shared, ['101', '102', '103', '104', '105']);

// Warm the WebSocket, HTTP and SSH paths once; not counted.
for ($i = 0; $i < 3 && ! in_array($mode, ['ssh', 'crash'], true); $i++) {
    $o = spike_order($nodeId, '101', "warm-$run-$i");
    spike_broadcast($nodeId, $o['envelope'], $reply);
    spike_collect([json_decode($o['payload'], true)['nonce']], 1.0);
    freeze($shared, ['101']);
}

if ($mode === 'seq') {
    for ($i = 0; $i < (int) $argv[3]; $i++) {
        $o = spike_order($nodeId, '101', "seq-$run-$i");
        $nonce = json_decode($o['payload'], true)['nonce'];
        $t0 = microtime(true);
        spike_broadcast($nodeId, $o['envelope'], $reply);
        $found = spike_collect([$nonce], $bound - (microtime(true) - $t0));
        if (isset($found[$nonce]) && $found[$nonce]['body']['status'] === 'done') {
            echo json_encode(['kind' => "order_$reply", 'i' => $i, 'miss' => false] + row($found[$nonce], $t0)), "\n";
        } else {
            // The fallback: today's SSH path does the same thaw and check.
            $out = trim((string) shell_exec(sshWake($shared, '101').' 2>/dev/null'));
            echo json_encode(['kind' => "order_$reply", 'i' => $i, 'miss' => true, 'fallback_ready_ms' => ms($out, $t0), 'fallback_ok' => ctype_digit($out)]), "\n";
            spike_collect([$nonce], 2.0); // drain the late result
        }
        freeze($shared, ['101']);
        usleep(100_000);
    }
}

if ($mode === 'burst') {
    $targets = ['101', '102', '103', '104', '105'];
    for ($round = 0; $round < (int) $argv[3]; $round++) {
        $orders = [];
        foreach ($targets as $t) {
            $orders[$t] = spike_order($nodeId, $t, "burst-$run-$round-$t");
        }
        $t0 = microtime(true);
        foreach ($orders as $o) {
            spike_broadcast($nodeId, $o['envelope'], $reply);
        }
        $nonces = array_map(fn ($o) => json_decode($o['payload'], true)['nonce'], $orders);
        $found = spike_collect(array_values($nonces), 2.0);
        foreach ($nonces as $t => $nonce) {
            echo json_encode(['kind' => "burst_$reply", 'round' => $round, 'target' => $t, 'miss' => ! isset($found[$nonce]) || ($found[$nonce]['seen_us'] / 1e6 - $t0) > $bound]
                + (isset($found[$nonce]) ? row($found[$nonce], $t0) : [])), "\n";
        }
        freeze($shared, $targets);
        usleep(200_000);
    }
}

if ($mode === 'same') {
    // Two Operations for one target at once: the second waits for the first.
    $a = spike_order($nodeId, '102', "same-$run-a");
    $b = spike_order($nodeId, '102', "same-$run-b");
    // One Operation in two orders at once: one acts, the other joins.
    $c1 = spike_order($nodeId, '103', "join-$run");
    $c2 = spike_order($nodeId, '103', "join-$run");
    $t0 = microtime(true);
    foreach ([$a, $b, $c1, $c2] as $o) {
        spike_broadcast($nodeId, $o['envelope'], $reply);
    }
    $nonces = array_map(fn ($o) => json_decode($o['payload'], true)['nonce'], ['a' => $a, 'b' => $b, 'c1' => $c1, 'c2' => $c2]);
    $found = spike_collect(array_values($nonces), 3.0);
    foreach ($nonces as $label => $nonce) {
        echo json_encode(['kind' => 'same', 'order' => $label] + (isset($found[$nonce]) ? row($found[$nonce], $t0) : ['missing' => true])), "\n";
    }
}

if ($mode === 'repeat') {
    // The Operation is done once; then the same Operation arrives again in a new, validly signed order,
    // as a fallback or retry would send it. The target is frozen again in between, so a second action
    // would have to thaw it; the first result comes back instead and the target stays frozen.
    $op = "repeat-$run";
    $first = spike_order($nodeId, '104', $op);
    $t0 = microtime(true);
    spike_broadcast($nodeId, $first['envelope'], $reply);
    $n1 = json_decode($first['payload'], true)['nonce'];
    echo json_encode(['kind' => 'repeat', 'order' => 'first'] + row(spike_collect([$n1], 2.0)[$n1], $t0)), "\n";
    freeze($shared, ['104']);
    $again = spike_order($nodeId, '104', $op);
    $t0 = microtime(true);
    spike_broadcast($nodeId, $again['envelope'], $reply);
    $n2 = json_decode($again['payload'], true)['nonce'];
    echo json_encode(['kind' => 'repeat', 'order' => 'same Operation, new order'] + row(spike_collect([$n2], 2.0)[$n2], $t0)), "\n";
    $state = trim((string) shell_exec("$shared ".escapeshellarg('cat /sys/fs/cgroup/system.slice/orbit-spike-target-104.service/cgroup.events | grep frozen')));
    echo json_encode(['kind' => 'repeat', 'target_after' => $state]), "\n";
}

if ($mode === 'ssh') {
    foreach (['ssh_shared' => $shared, 'ssh_fresh' => $fresh] as $kind => $cmd) {
        for ($i = 0; $i < (int) $argv[2]; $i++) {
            $t0 = microtime(true);
            $out = trim((string) shell_exec(sshWake($cmd, '101').' 2>/dev/null'));
            $t1 = microtime(true);
            echo json_encode(['kind' => $kind, 'i' => $i, 'ok' => ctype_digit($out), 'ready_ms' => ms($out, $t0), 'done_ms' => ($t1 - $t0) * 1000]), "\n";
            freeze($shared, ['101']);
            usleep(100_000);
        }
    }
}

if ($mode === 'crash') {
    // The executor is killed during this order's pause between thaw and readiness (from outside).
    $op = "crash-$run";
    $o = spike_order($nodeId, '105', $op);
    $t0 = microtime(true);
    spike_broadcast($nodeId, $o['envelope'], $reply);
    $item = spike_redis()->blPop(['spike:results'], 8.0);
    $first = $item ? json_decode($item[1], true) : null;
    echo json_encode(['kind' => 'crash', 'order' => 'original', 'after_ms' => (microtime(true) - $t0) * 1000, 'result' => $first['body'] ?? null]), "\n";
    sleep(3);
    $again = spike_order($nodeId, '105', $op);
    $t0 = microtime(true);
    spike_broadcast($nodeId, $again['envelope'], $reply);
    $n = json_decode($again['payload'], true)['nonce'];
    $r = spike_collect([$n], 2.0)[$n] ?? null;
    echo json_encode(['kind' => 'crash', 'order' => 'same Operation resent', 'result' => $r['body'] ?? null]), "\n";
}

shell_exec("$shared -O exit 2>/dev/null");
