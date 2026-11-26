<?php

// A NATS peer for the signed read's deadline tests: it greets, answers PING, and never replies to a request. With `close`
// it closes the connection once a request's message arrived. It prints its port and serves until it is stopped.
$close = ($argv[1] ?? '') === 'close';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
echo explode(':', stream_socket_get_name($server, false))[1], "\n";
fflush(STDOUT);
while ($peer = @stream_socket_accept($server, 60)) {
    fwrite($peer, "INFO {\"server_id\":\"fixture\",\"max_payload\":1048576}\r\n");
    while (($line = fgets($peer)) !== false) {
        if (str_starts_with($line, 'PING')) {
            fwrite($peer, "PONG\r\n");
        } elseif (str_starts_with($line, 'PUB ') || str_starts_with($line, 'HPUB ')) {
            fgets($peer);
            if ($close && ! str_contains($line, '$JS.')) {
                break;
            }
        }
    }
    fclose($peer);
}
