<?php
// SPIKE(signed-orders) round 3: sleep and wake cycles through the observer's channel. Run from apps/gateway
// as orbit. The result reaches this request through the agent-view subscriber and a blocking pop on
// `spike:op:<Operation>`. Prints JSON lines.
//   php bench3.php cycle <cycles> <threads> [target]   sleep, then wake, of one target
//   php bench3.php burst <rounds> <threads>            sleep 101..105, then wake all five at once
//   php bench3.php crash                               the wake of 105 with the executor killed mid-wake
// After 300 ms without a result, the fallback hands the same signed order to the Node's relay over SSH.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/opt/orbit-spike/order.php';

// Round 4: the Node comes from SPIKE_NODE (3 = nested-app-dev, 4 = nested-app-prod).
define('SPIKE_NODE', (int) (getenv('SPIKE_NODE') ?: 3));
$nodeId = SPIKE_NODE;
$bound = 0.300;
$node = App\Models\Node::query()->findOrFail($nodeId);
$key = app(App\Infrastructure\Ssh\SshKeyProvider::class)->privateKeyPath();
$ssh = sprintf('ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/orbit-spike-known-hosts -i %s -o ControlMaster=auto -o ControlPath=/tmp/orbit-spike-ssh-%%C -o ControlPersist=300 %s@%s',
    escapeshellarg($key), escapeshellarg($node->user), escapeshellarg($node->wireguard_ip));

/** Round 4: the restore pool is the Node's; its size is set before the executor starts. */
function pool_size(string $ssh, int $threads): void
{
    on_node($ssh, "echo $threads | sudo -n tee /etc/orbit-spike/restore-threads >/dev/null && sudo -n systemctl restart orbit-spike-executor3 && sleep 0.5");
}

function on_node(string $ssh, string $command): string
{
    return trim((string) shell_exec("$ssh ".escapeshellarg($command).' 2>&1'));
}

/** Waits for the result of one Operation: a blocking pop on its own key. */
function wait_op(string $op, float $timeout): ?array
{
    if ($timeout <= 0) {
        return null;
    }
    $item = spike_redis()->blPop(["spike:op:$op"], max(0.01, $timeout));
    if (! $item) {
        return null;
    }
    $r = json_decode($item[1], true);
    $r['seen_us'] = (int) (microtime(true) * 1e6);

    return $r;
}

/** The SSH fallback: the same signed order, through the Node's one fixed relay command. */
function relay(string $ssh, string $envelope): ?array
{
    $out = shell_exec("printf %s ".escapeshellarg($envelope)." | $ssh ".escapeshellarg('sudo -n -u orbit-spike-relay /usr/local/libexec/orbit-spike-executor3 relay').' 2>/dev/null');

    return json_decode((string) $out, true);
}

function ms(?string $ns, float $t0): ?float
{
    return $ns === null || $ns === '' ? null : (int) $ns / 1e6 - $t0 * 1000;
}

/** Sends one order and waits for its result; on a miss, the fallback. */
function order(string $ssh, string $target, string $action, string $op, float $bound, float $wait): array
{
    $o = spike_order(SPIKE_NODE, $target, $op, action: $action);
    $t0 = microtime(true);
    spike_broadcast(SPIKE_NODE, $o['envelope'], 'channel');
    $r = wait_op($op, $bound);
    $row = ['t0' => $t0, 'miss' => $r === null];
    if ($r === null) {
        $relayed = relay($ssh, $o['envelope']);
        $row['fallback_ms'] = (microtime(true) - $t0) * 1000;
        $row['fallback'] = $relayed['status'] ?? null;
        $row['fallback_reason'] = $relayed['reason'] ?? null;
        $row['fallback_first_result'] = isset($relayed['first_result']);
        $r = wait_op($op, $wait); // the late WebSocket result, if any
        if ($r === null && $relayed !== null) {
            $r = ['body' => $relayed['first_result'] ?? $relayed, 'received_us' => null, 'seen_us' => (int) (microtime(true) * 1e6)];
        }
    }
    $row['r'] = $r;

    return $row;
}

function wake_row(array $o): array
{
    $t0 = $o['t0'];
    $r = $o['r'] ?? [];
    $b = $r['body'] ?? [];
    $restore = $b['restore'] ?? [];

    return [
        'miss' => $o['miss'], 'status' => $b['status'] ?? null, 'restore' => $restore['status'] ?? null,
        'pages' => $restore['pages'] ?? null, 'failed_pages' => $restore['failed_pages'] ?? null, 'threads' => $restore['threads'] ?? null,
        'restore_ms' => $restore['restore_ms'] ?? null, 'mb_per_s' => $restore['mb_per_s'] ?? null, 'identity_ms' => $restore['identity_ms'] ?? null,
        'observer_ms' => ms($r['observer_received_ns'] ?? null, $t0), 'accepted_ms' => ms($b['accepted_ns'] ?? null, $t0),
        'verified_ms' => ms($b['verified_ns'] ?? null, $t0), 'wake_start_ms' => ms($b['wake_start_ns'] ?? null, $t0),
        'restore_end_ms' => ms($b['restore_end_ns'] ?? null, $t0), 'thawed_ms' => ms($b['thawed_ns'] ?? null, $t0),
        'limits_closed_ms' => ms($b['limits_closed_ns'] ?? null, $t0), 'ready_ms' => ms($b['ready_ns'] ?? null, $t0),
        'observer_replied_ms' => ms($r['observer_replied_ns'] ?? null, $t0),
        'listener_ms' => isset($r['received_us']) ? $r['received_us'] / 1000 - $t0 * 1000 : null,
        'seen_ms' => isset($r['seen_us']) ? $r['seen_us'] / 1000 - $t0 * 1000 : null,
        'handoff_ms' => isset($r['received_us'], $r['seen_us']) ? ($r['seen_us'] - $r['received_us']) / 1000 : null,
        'limits_closed' => $b['limits_closed'] ?? null, 'recovered' => $b['recovered'] ?? null, 'repeat' => $b['repeat'] ?? null,
        'fallback' => $o['fallback'] ?? null, 'fallback_reason' => $o['fallback_reason'] ?? null, 'fallback_ms' => $o['fallback_ms'] ?? null,
    ];
}

function sleep_row(array $o): array
{
    $b = $o['r']['body'] ?? [];
    $keys = ['status', 'processes', 'pages', 'runs', 'plan_bytes', 'opened_limits', 'limits_ms', 'freeze_ms', 'scan_ms', 'plan_ms', 'reclaim_ms',
        'reclaim_rounds', 'total_ms', 'memory_before', 'swap_before', 'memory_after', 'swap_after', 'anon_after', 'file_after'];

    return ['miss' => $o['miss'], 'result_ms' => isset($o['r']['seen_us']) ? $o['r']['seen_us'] / 1000 - $o['t0'] * 1000 : null]
        + array_intersect_key($b, array_flip($keys));
}

$mode = $argv[1];
$run = bin2hex(random_bytes(3));

if ($mode === 'cycle') {
    $target = $argv[4] ?? '101';
    pool_size($ssh, (int) $argv[3]);
    for ($i = 0; $i < (int) $argv[2]; $i++) {
        $s = order($ssh, $target, 'sleep', "sleep-$run-$i", 10.0, 5.0);
        echo json_encode(['kind' => 'sleep', 'i' => $i] + sleep_row($s)), "\n";
        usleep(300_000);
        $w = order($ssh, $target, 'wake', "wake-$run-$i", $bound, 5.0);
        echo json_encode(['kind' => 'wake', 'threads' => (int) $argv[3], 'i' => $i] + wake_row($w)), "\n";
        usleep(300_000);
    }
}

if ($mode === 'burst') {
    $targets = array_slice(['101', '102', '103', '104', '105'], 0, (int) (getenv('SPIKE_TARGETS') ?: 5));
    pool_size($ssh, (int) ($argv[3] ?? 4));
    for ($round = 0; $round < (int) $argv[2]; $round++) {
        $ops = [];
        foreach ($targets as $t) {
            $ops[$t] = "bsleep-$run-$round-$t";
            spike_broadcast(SPIKE_NODE, spike_order(SPIKE_NODE, $t, $ops[$t], action: 'sleep')['envelope'], 'channel');
        }
        foreach ($ops as $t => $op) {
            $r = wait_op($op, 15.0);
            echo json_encode(['kind' => 'burst_sleep', 'round' => $round, 'target' => $t] + sleep_row(['miss' => $r === null, 'r' => $r, 't0' => 0])), "\n";
        }
        usleep(300_000);
        $orders = [];
        foreach ($targets as $t) {
            $orders[$t] = spike_order(SPIKE_NODE, $t, "bwake-$run-$round-$t", action: 'wake');
        }
        $t0 = microtime(true);
        foreach ($orders as $o) {
            spike_broadcast(SPIKE_NODE, $o['envelope'], 'channel');
        }
        foreach ($targets as $t) {
            $r = wait_op("bwake-$run-$round-$t", 10.0);
            $row = wake_row(['t0' => $t0, 'miss' => $r === null, 'r' => $r]);
            $row['miss'] = $r === null || $row['seen_ms'] > $bound * 1000;
            echo json_encode(['kind' => 'burst_wake', 'round' => $round, 'target' => $t] + $row), "\n";
        }
        usleep(300_000);
    }
}

if ($mode === 'crash') {
    // Sleep 105, then wake it with a 3 s pause after the restore; the executor is killed during the pause.
    $s = order($ssh, '105', 'sleep', "csleep-$run", 10.0, 5.0);
    echo json_encode(['kind' => 'crash_sleep'] + sleep_row($s)), "\n";
    on_node($ssh, 'echo 3000 | sudo -n tee /etc/orbit-spike/test-pause-ms');
    file_put_contents('/tmp/orbit-spike-crash-go', '1');
    $op = "cwake-$run";
    $o = spike_order(SPIKE_NODE, '105', $op, action: 'wake');
    $t0 = microtime(true);
    spike_broadcast(SPIKE_NODE, $o['envelope'], 'channel');
    $first = spike_redis()->blPop(["spike:op:$op", 'spike:results'], 10.0);
    echo json_encode(['kind' => 'crash', 'step' => 'original order', 'after_ms' => (microtime(true) - $t0) * 1000, 'result' => $first ? json_decode($first[1], true)['body'] : null]), "\n";
    sleep(2);
    $again = spike_order(SPIKE_NODE, '105', $op, action: 'wake');
    $t0 = microtime(true);
    spike_broadcast(SPIKE_NODE, $again['envelope'], 'channel');
    $r = wait_op($op, 5.0);
    echo json_encode(['kind' => 'crash', 'step' => 'same Operation resent', 'result' => $r['body'] ?? null]), "\n";
    $relayed = relay($ssh, $again['envelope']);
    echo json_encode(['kind' => 'crash', 'step' => 'same signed order through the SSH relay', 'result' => $relayed]), "\n";
    echo json_encode(['kind' => 'crash', 'step' => 'target after', 'events' => on_node($ssh, 'grep frozen /sys/fs/cgroup/system.slice/orbit-spike-target-105.service/cgroup.events; cat /sys/fs/cgroup/system.slice/orbit-spike-target-105.service/memory.swap.max; curl -s http://127.0.0.1:8105/')]), "\n";
}

if ($mode === 'relay') {
    // The fallback path on its own: one wake through the relay, then the same order again through the
    // WebSocket, which must not act twice.
    $s = order($ssh, '104', 'sleep', "rsleep-$run", 10.0, 5.0);
    $op = "rwake-$run";
    $o = spike_order(SPIKE_NODE, '104', $op, action: 'wake');
    $t0 = microtime(true);
    $relayed = relay($ssh, $o['envelope']);
    echo json_encode(['kind' => 'relay', 'step' => 'wake through the SSH relay', 'ms' => (microtime(true) - $t0) * 1000, 'status' => $relayed['status'] ?? null, 'peer' => $relayed['peer'] ?? null, 'pages' => $relayed['restore']['pages'] ?? null]), "\n";
    spike_broadcast(SPIKE_NODE, $o['envelope'], 'channel');
    $r = wait_op($op, 3.0);
    echo json_encode(['kind' => 'relay', 'step' => 'same signed order through the WebSocket', 'status' => $r['body']['status'] ?? null, 'reason' => $r['body']['reason'] ?? null, 'first_result' => isset($r['body']['first_result'])]), "\n";
}

shell_exec("$ssh -O exit 2>/dev/null");
