<?php

declare(strict_types=1);

/**
 * Writes a self-signed CA certificate for 127.0.0.1 and its key to $directory/$name.{pem,key}.
 * The server presents the CA certificate itself, so trusting that CA is enough to accept it.
 */
function orbit_https_ca(string $directory, string $name): string
{
    $config = $directory.'/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=IP:127.0.0.1,DNS:reverb.orbit\nkeyUsage=digitalSignature,keyCertSign\n");
    $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'];
    $key = openssl_pkey_new($options);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => "{$name} test CA"], $key, $options), null, $key, 1, $options);
    openssl_x509_export_to_file($certificate, "{$directory}/{$name}.pem");
    openssl_pkey_export_to_file($key, "{$directory}/{$name}.key", null, ['config' => $config]);

    return "{$directory}/{$name}.pem";
}

/**
 * Starts the fixture server with the named CA's certificate, adds it to $servers, and returns its port.
 *
 * @param  list<resource>  $servers
 */
function orbit_https_server(array &$servers, string $directory, string $name, string $mode = 'reject'): int
{
    $process = proc_open(
        [PHP_BINARY, base_path('tests/Fixtures/https-server.php'), "{$directory}/{$name}.pem", "{$directory}/{$name}.key", $mode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('The test HTTPS server did not start.');
    }

    $servers[] = $process;
    $line = (string) fgets($pipes[1]);

    if (! str_starts_with($line, 'port=')) {
        throw new RuntimeException('The test HTTPS server did not listen: '.stream_get_contents($pipes[2]));
    }

    return (int) substr(trim($line), 5);
}
