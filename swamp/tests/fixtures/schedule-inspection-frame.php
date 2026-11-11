<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\StreamWebSocketClient;

$gateway = dirname(__DIR__, 3).'/apps/gateway/app';
require $gateway.'/Infrastructure/AgentView/WebSocketClient.php';
require $gateway.'/Infrastructure/AgentView/StreamWebSocketClient.php';
require $gateway.'/Support/ValidatedData.php';

// Send the actual private inspection JSON as a JSON string in one native vault response frame.
$value = file_get_contents($argv[1]);
$payload = json_encode(['id' => 'fixture', 'type' => 'vault.read-secret', 'payload' => ['data' => [
    'vaultName' => 'orbit-control-inputs', 'secretKey' => 'fixture', 'vaultType' => 'local_encryption', 'value' => $value,
]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$pid = pcntl_fork();
if ($pid === 0) {
    fclose($pair[0]);
    $frame = "\x81\x7f".pack('J', strlen($payload)).$payload;
    while ($frame !== '') {
        $written = @fwrite($pair[1], $frame);
        if ($written === false || $written === 0) {
            exit(1);
        }
        $frame = substr($frame, $written);
    }
    fclose($pair[1]);
    exit(0);
}
fclose($pair[1]);
stream_set_blocking($pair[0], false);
$client = StreamWebSocketClient::forSwamp();
// Supply an established transport; frame parsing, bounds and JSON decoding remain the production path.
(new ReflectionProperty($client, 'stream'))->setValue($client, $pair[0]);
$deadline = microtime(true) + 10;
$messages = [];
while ($client->isConnected() && $messages === [] && microtime(true) < $deadline) {
    $messages = $client->receive(1);
}
$client->close();
pcntl_waitpid($pid, $status);
if (($messages[0]['payload']['data']['value'] ?? null) !== $value || pcntl_wexitstatus($status) !== 0) {
    fwrite(STDERR, "The Gateway did not receive the complete native inspection frame.\n");
    exit(1);
}
$output = json_decode($value, true, flags: JSON_THROW_ON_ERROR)['output'];
echo json_encode(['frame_bytes' => strlen($payload), 'entries' => substr_count($output, "\n")], JSON_THROW_ON_ERROR)."\n";
