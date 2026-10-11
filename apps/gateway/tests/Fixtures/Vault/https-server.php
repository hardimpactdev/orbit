<?php

declare(strict_types=1);

/*
 * A TLS server for HttpVaultClient trust tests. It answers every request like Vaultwarden
 * refusing an API key: HTTP 400 with `invalid_client`.
 *
 * Usage: php https-server.php CERT KEY
 * It prints `port=N` once it listens and serves until the test kills it.
 */

[, $cert, $key] = $argv;

$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, 'port='.substr($name, strrpos($name, ':') + 1)."\n");
fflush(STDOUT);

while (true) {
    // A client that rejects the certificate aborts the handshake, so accept fails and the loop goes on.
    $connection = @stream_socket_accept($server, -1);

    if ($connection === false) {
        continue;
    }

    $request = '';

    while (! str_contains($request, "\r\n\r\n") && ($chunk = fread($connection, 8192)) !== false && $chunk !== '') {
        $request .= $chunk;
    }

    $body = '{"error":"invalid_client"}';
    fwrite($connection, "HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    fclose($connection);
}
