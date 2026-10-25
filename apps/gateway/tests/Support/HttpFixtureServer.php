<?php

declare(strict_types=1);

namespace Tests\Support;

/** A loopback HTTP fixture that can retain stalled connections while serving cleanup requests. */
final class HttpFixtureServer
{
    /** @var resource */
    private $server;

    /** @var list<resource> */
    private array $stalled = [];

    public function __construct()
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        if ($this->server === false) {
            throw new \RuntimeException($error, $code);
        }
        echo substr(strrchr(stream_socket_get_name($this->server, false), ':'), 1)."\n";
        fflush(STDOUT);
    }

    /** @param callable(string, string, array<string, string>, string): array{int, string, array<string, string>, int, bool} $handler */
    public function serve(callable $handler, bool $stallTls = false): never
    {
        while (true) {
            $client = stream_socket_accept($this->server, -1);
            if ($client === false) {
                continue;
            }
            if ($stallTls) {
                $this->stalled[] = $client;

                continue;
            }
            stream_set_timeout($client, 10);
            $line = fgets($client);
            if ($line === false) {
                fclose($client);

                continue;
            }
            [$method, $path] = explode(' ', trim($line));
            $headers = [];
            while (($line = fgets($client)) !== false && trim($line) !== '') {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
            if (strtolower($headers['expect'] ?? '') === '100-continue') {
                fwrite($client, "HTTP/1.1 100 Continue\r\n\r\n");
            }
            $body = '';
            $length = (int) ($headers['content-length'] ?? 0);
            while (strlen($body) < $length) {
                $part = fread($client, $length - strlen($body));
                if ($part === false || $part === '') {
                    break;
                }
                $body .= $part;
            }
            [$status, $body, $responseHeaders, $chunk, $stall] = $handler($method, $path, $headers, $body);
            $head = "HTTP/1.0 {$status} Fixture\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n";
            foreach ($responseHeaders as $name => $value) {
                $head .= $name.': '.$value."\r\n";
            }
            $this->write($client, $head."\r\n");
            if ($stall) {
                $this->write($client, substr($body, 0, 8));
                $this->stalled[] = $client;

                continue;
            }
            for ($offset = 0; $offset < strlen($body); $offset += $chunk) {
                if (! $this->write($client, substr($body, $offset, $chunk))) {
                    break;
                }
            }
            fclose($client);
        }
    }

    /** @param resource $client */
    private function write($client, string $bytes): bool
    {
        while ($bytes !== '') {
            $written = @fwrite($client, $bytes);
            if ($written === false || $written === 0) {
                return false;
            }
            $bytes = substr($bytes, $written);
        }

        return true;
    }
}
