<?php
// SPIKE(signed-orders): the order-level security tests through the real path (Gateway → Reverb → observer →
// executor). Each tampered order is what an attacker who controls Reverb or the observer could send.
// With "envelope <file>" it only writes one valid order for the caller tests on the Node.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/opt/orbit-spike/order.php';
$node = 3;

if (($argv[1] ?? '') === 'envelope') {
    file_put_contents($argv[2], spike_order($node, '101', 'op-caller-test-'.bin2hex(random_bytes(3)), action: 'wake', ttlMs: 8000)['envelope']);
    exit;
}

if (($argv[1] ?? '') === 'replay-restart') {
    // The executor restarts between the two sends (from outside); its persisted nonce set must refuse the second.
    $replay = spike_order($node, '101', 'op-sec-replay-restart', ttlMs: 9000)['envelope'];
    echo json_encode(['test' => 'replay across restart: first send', 'result' => send($replay)]), "\n";
    sleep(4);
    echo json_encode(['test' => 'replay across restart: same order after restart', 'result' => send($replay)]), "\n";
    exit;
}

/** Swap one field of a signed payload and keep the old signature. */
function tamper(string $envelope, string $field, mixed $value): string
{
    [$p, $s] = explode('.', $envelope);
    $order = json_decode(base64_decode($p), true);
    $order[$field] = $value;

    return base64_encode(json_encode($order, JSON_UNESCAPED_SLASHES)).'.'.$s;
}

function send(string $envelope): ?array
{
    // Round 3: results come back on the channel, to the Operation's own key when the order names one.
    $op = json_decode((string) base64_decode(explode('.', $envelope)[0]), true)['operation'] ?? 'none';
    spike_redis()->del('spike:results', "spike:op:$op");
    spike_broadcast(3, $envelope, 'channel');
    $item = spike_redis()->blPop(["spike:op:$op", 'spike:results'], 3.0);

    return $item ? json_decode($item[1], true)['body'] : null;
}

$tests = [];
$other = sodium_crypto_sign_keypair();
$o = spike_order($node, '101', 'op-sec-badsig');
[$p] = explode('.', $o['envelope']);
$tests['bad signature (other key)'] = base64_encode($o['payload']).'.'.base64_encode(sodium_crypto_sign_detached($o['payload'], sodium_crypto_sign_secretkey($other)));
$tests['changed action, old signature'] = tamper(spike_order($node, '101', 'op-sec-action')['envelope'], 'action', 'destroy');
$tests['changed target, old signature'] = tamper(spike_order($node, '101', 'op-sec-target')['envelope'], 'target', '102');
$tests['expired order (signed, 1 s old)'] = spike_order($node, '101', 'op-sec-expired', ttlMs: -1000)['envelope'];
$tests['unknown action (signed)'] = spike_order($node, '101', 'op-sec-unknown', action: 'shell')['envelope'];
$tests['wrong target (signed, not on this Node)'] = spike_order($node, '999', 'op-sec-wrongtarget')['envelope'];
$tests['wrong node (signed for Node 4)'] = spike_order(4, '101', 'op-sec-wrongnode')['envelope'];
$tests['expiry too far ahead (signed, 60 s)'] = spike_order($node, '101', 'op-sec-ttl', ttlMs: 60_000)['envelope'];
$tests['garbage bytes'] = 'not-an-order';

foreach ($tests as $name => $envelope) {
    echo json_encode(['test' => $name, 'result' => send($envelope)]), "\n";
}
$replay = spike_order($node, '101', 'op-sec-replay')['envelope'];
echo json_encode(['test' => 'replay: first send (valid)', 'result' => send($replay)]), "\n";
echo json_encode(['test' => 'replay: same order again', 'result' => send($replay)]), "\n";
