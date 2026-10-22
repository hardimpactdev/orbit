<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/** A real TLS WebSocket peer with a private certificate for reverb.orbit. */
final class ScriptedWebSocketServer
{
    public readonly int $port;

    private Process $process;

    private InputStream $input;

    private string $buffer = '';

    /** @var list<string> */
    private array $lines = [];

    public function __construct(public readonly string $caPath, string $keyPath, string $mode, array $arguments = [])
    {
        $command = [PHP_BINARY, base_path('tests/Fixtures/AgentView/websocket-server.php'), $caPath, $keyPath, $mode, ...$arguments];
        $this->process = new Process($command, timeout: 60);
        $this->process->setInput($this->input = new InputStream);
        try {
            $this->process->start();
            $port = SubprocessFixture::line($this->process);
            if (! str_starts_with($port, 'port=')) {
                throw new RuntimeException('The test WebSocket server did not print its port: '.$this->process->getErrorOutput());
            }
        } catch (Throwable $exception) {
            $this->process->stop();

            throw $exception;
        }
        $this->port = (int) substr($port, 5);
    }

    public static function start(string $mode, array $arguments = []): self
    {
        $directory = sys_get_temp_dir().'/orbit-ws-'.Str::random(8);
        mkdir($directory);
        $config = $directory.'/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=DNS:reverb.orbit\nkeyUsage=digitalSignature,keyCertSign\n");
        $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'reverb.orbit'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export_to_file($certificate, $directory.'/cert.pem');
        openssl_pkey_export_to_file($key, $directory.'/key.pem', null, ['config' => $config]);

        return new self($directory.'/cert.pem', $directory.'/key.pem', $mode, $arguments);
    }

    public function endpoint(string $serverName = 'reverb.orbit'): WebSocketEndpoint
    {
        return new WebSocketEndpoint('127.0.0.1', $this->port, $serverName, '/app/key?protocol=7', $this->caPath);
    }

    /** Waits for the next server line that starts with $prefix. */
    public function waitFor(string $prefix, float $seconds = 10.0): string
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $running = $this->process->isRunning();
            $this->buffer .= $this->process->getIncrementalOutput();
            while (($newline = strpos($this->buffer, "\n")) !== false) {
                $this->lines[] = rtrim(substr($this->buffer, 0, $newline));
                $this->buffer = substr($this->buffer, $newline + 1);
            }

            foreach ($this->lines as $index => $line) {
                if (str_starts_with($line, $prefix)) {
                    unset($this->lines[$index]);

                    return $line;
                }
            }

            if (! $running) {
                break;
            }

            usleep(10_000);
        }

        throw new RuntimeException("The test WebSocket server never printed [{$prefix}]: ".$this->process->getErrorOutput());
    }

    /** Waits until the server exits, so a reset it sent has reached the client. */
    public function waitForExit(float $seconds = 5.0): void
    {
        $deadline = microtime(true) + $seconds;

        while ($this->process->isRunning()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The test WebSocket server did not exit.');
            }

            usleep(10_000);
        }
    }

    public function dropConnection(): void
    {
        $this->input->write("drop\n");
        $this->waitFor('dropped=');
    }

    /** @return list<array<string, mixed>> */
    public static function messages(StreamWebSocketClient $client, int $count, float $seconds = 10.0): array
    {
        $messages = [];
        $deadline = microtime(true) + $seconds;

        while (count($messages) < $count && $client->isConnected() && microtime(true) < $deadline) {
            array_push($messages, ...$client->receive(0.2));
        }

        return $messages;
    }

    public function stop(): void
    {
        $this->input->close();
        $this->process->stop();
    }
}
