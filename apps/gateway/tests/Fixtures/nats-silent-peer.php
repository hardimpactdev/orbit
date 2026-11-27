<?php

// A NATS peer for the signed read's deadline tests. It greets, answers PING, and never replies to a request. Modes:
// `close` closes the connection once a request's message arrived; `stall` greets late and never answers PING; `nobody`
// answers each request as NATS does when no one subscribes (503); `partial` closes after reading 4,096 bytes of a request;
// `refuse` refuses each request with `-ERR`; `trickle` sends a reply's header at once and its body 300 ms later. It prints its
// port and serves until it is stopped.
$mode = $argv[1] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
echo explode(':', stream_socket_get_name($server, false))[1], "\n";
fflush(STDOUT);
while ($peer = @stream_socket_accept($server, 60)) {
    if ($mode === 'stall') {
        usleep(600_000);
    }
    fwrite($peer, "INFO {\"server_id\":\"fixture\",\"max_payload\":1048576,\"headers\":true}\r\n");
    while (($line = fgets($peer)) !== false) {
        if (str_starts_with($line, 'PING')) {
            if ($mode !== 'stall') {
                fwrite($peer, "PONG\r\n");
            }
        } elseif (str_starts_with($line, 'PUB ') || str_starts_with($line, 'HPUB ')) {
            if ($mode === 'partial') {
                fread($peer, 4096);
                break;
            }
            fgets($peer);
            $parts = explode(' ', trim($line));
            if ($mode === 'refuse') {
                fwrite($peer, "-ERR 'Maximum Payload Violation'\r\n");
                break;
            }
            if ($mode === 'trickle') {
                fwrite($peer, "MSG {$parts[2]} 1 7\r\n");
                usleep(300_000);
                fwrite($peer, "{\"a\":1}\r\n");
            }
            if ($mode === 'close') {
                break;
            }
            if ($mode === 'nobody') {
                $headers = "NATS/1.0 503\r\n\r\n";
                fwrite($peer, "HMSG {$parts[2]} 1 ".strlen($headers).' '.strlen($headers)."\r\n{$headers}\r\n");
            }
        }
    }
    fclose($peer);
}
