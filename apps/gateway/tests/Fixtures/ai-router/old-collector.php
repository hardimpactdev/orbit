<?php

declare(strict_types=1);

// A serving retired Process and its cached quota, for the removal/cutover rehearsal.
// Quota parsing belongs to the current collector's Rust tests.
$account = ['id' => 'codex-1', 'provider' => 'codex', 'label' => 'Retired fixture', 'disabled' => false, 'windows' => [['label' => '5h', 'used_percent' => 25, 'remaining_percent' => 75, 'resets_at' => null]]];
$snapshot = ['accounts' => [$account], 'providers' => [['provider' => 'codex', 'accounts' => [$account], 'windows' => $account['windows']]], 'models' => [['id' => 'test-model', 'provider' => 'codex']], 'collected_at' => gmdate('c')];
$socket = stream_socket_client('tcp://'.getenv('PROXYCLI_CACHE_HOST').':'.getenv('PROXYCLI_CACHE_PORT'), timeout: 3);
if ($socket === false) {
    exit(1);
}
$parts = ['SET', 'orbit:proxycli:snapshot', json_encode($snapshot, JSON_THROW_ON_ERROR)];
$payload = '*'.count($parts)."\r\n";
foreach ($parts as $part) {
    $payload .= '$'.strlen($part)."\r\n".$part."\r\n";
}
fwrite($socket, $payload);
if (fgets($socket) !== "+OK\r\n") {
    exit(1);
}
fclose($socket);
$server = stream_socket_server('tcp://127.0.0.1:8787');
if ($server === false) {
    exit(1);
}
while ($client = stream_socket_accept($server, -1)) {
    while (($line = fgets($client)) !== false && trim($line) !== '') {
        // Consume the request headers before sending the fixed retired snapshot.
    }
    $body = json_encode($snapshot, JSON_THROW_ON_ERROR);
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    fclose($client);
}
