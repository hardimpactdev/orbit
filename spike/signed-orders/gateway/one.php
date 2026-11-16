<?php
// SPIKE(signed-orders): send one order and print the result. Run from apps/gateway as orbit.
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/opt/orbit-spike/order.php';
$o = spike_order(3, '101', 'op-smoke');
$nonce = json_decode($o['payload'], true)['nonce'];
$t0 = microtime(true);
spike_broadcast(3, $o['envelope']);
$r = spike_result($nonce, 2.0);
printf("%.1f ms %s\n", (microtime(true) - $t0) * 1000, json_encode($r['body'] ?? null));
