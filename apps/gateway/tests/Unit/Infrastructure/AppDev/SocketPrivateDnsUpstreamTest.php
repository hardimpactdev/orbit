<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsAnswer;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\SocketPrivateDnsUpstream;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\SilentPrivateDnsPortPair;
use Tests\Support\SubprocessFixture;

it('returns a UDP upstream answer without waiting for the socket to close', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $query = $codec->encodeQuery('example.com');
    $response = $codec->encodeAnswer($codec->decodeQuestion($query), PrivateDnsAnswer::a('1.2.3.4'));
    $responder = orb313_start_udp_responder($response);

    try {
        $resolved = new SocketPrivateDnsUpstream('127.0.0.1', $responder['port'], 5.0)->resolve($query);

        expect($resolved)
            ->toBe($response)
            ->and($responder['process']->isRunning())
            ->toBeTrue();
    } finally {
        orb313_stop_process($responder);
    }
});

it('fails a silent UDP upstream without blocking the timeout window twice', function (): void {
    [$silentUdp, $silentTcp, $port] = SilentPrivateDnsPortPair::hold('127.0.0.1');

    try {
        // Neither transport may be adopted by another test's PHP stream server.
        set_error_handler(static fn (): bool => true);
        try {
            expect(stream_socket_server('udp://127.0.0.1:'.$port, $error, $message, STREAM_SERVER_BIND))->toBeFalse();
            expect(stream_socket_server('tcp://127.0.0.1:'.$port, $error, $message))->toBeFalse();
        } finally {
            restore_error_handler();
        }

        $waits = [];
        $wait = static function ($socket, bool $write, float $timeout) use (&$waits): bool {
            $waits[] = ['write' => $write, 'timeout' => $timeout];

            return $write;
        };
        expect(fn () => new SocketPrivateDnsUpstream('127.0.0.1', $port, 0.2, $wait, static fn (): float => 0.0)->resolve(
            new PrivateDnsMessageCodec()->encodeQuery('example.com'),
        ))->toThrow(RuntimeException::class);
        expect($waits)->toBe([
            ['write' => true, 'timeout' => 0.2],
            ['write' => false, 'timeout' => 0.2],
        ]);
    } finally {
        socket_close($silentUdp);
        socket_close($silentTcp);
    }
});

it('gives up on a TCP upstream that accepts but never sends a response', function (): void {
    $codec = new PrivateDnsMessageCodec;
    $query = $codec->encodeQuery('example.com');
    $responder = orb313_start_stalled_tcp_acceptor();

    try {
        $waits = [];
        $wait = static function ($socket, bool $write, float $timeout) use (&$waits): bool {
            $waits[] = ['write' => $write, 'timeout' => $timeout];

            return $write;
        };
        expect(fn () => new SocketPrivateDnsUpstream('127.0.0.1', $responder['port'], 0.2, $wait, static fn (): float => 0.0)->resolve($query))
            ->toThrow(RuntimeException::class, 'The private DNS upstream TCP response timed out.');
        expect($waits)->toBe([
            ['write' => true, 'timeout' => 0.2],
            ['write' => false, 'timeout' => 0.2],
            ['write' => true, 'timeout' => 0.2],
            ['write' => false, 'timeout' => 0.2],
        ]);
    } finally {
        orb313_stop_process($responder);
    }
});

/**
 * @return array{process: Process, input: InputStream, port: int, files: list<string>}
 */
function orb313_start_udp_responder(string $response): array
{
    $script = <<<'PHP'
        <?php

        declare(strict_types=1);

        $response = hex2bin($argv[1]);
        if (! is_string($response)) {
            exit(1);
        }

        $server = stream_socket_server('udp://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND);
        if (! is_resource($server)) {
            exit(1);
        }

        $name = stream_socket_get_name($server, false);
        if (! is_string($name) || ! str_contains($name, ':')) {
            exit(1);
        }

        echo substr($name, strrpos($name, ':') + 1)."\n";
        fflush(STDOUT);
        $peer = '';
        $message = stream_socket_recvfrom($server, 65535, 0, $peer);
        if (is_string($message) && $peer !== '') {
            stream_socket_sendto($server, $response, 0, $peer);
        }

        fread(STDIN, 1);
        PHP;

    return orb313_start_script($script, [bin2hex($response)]);
}

/**
 * @return array{process: Process, input: InputStream, port: int, files: list<string>}
 */
function orb313_start_stalled_tcp_acceptor(): array
{
    $script = <<<'PHP'
        <?php

        declare(strict_types=1);

        $server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if (! is_resource($server)) {
            exit(1);
        }

        $name = stream_socket_get_name($server, false);
        if (! is_string($name) || ! str_contains($name, ':')) {
            exit(1);
        }

        echo substr($name, strrpos($name, ':') + 1)."\n";
        fflush(STDOUT);
        $connection = @stream_socket_accept($server, -1);
        if (is_resource($connection)) {
            fread(STDIN, 1);
            fclose($connection);
        }
        PHP;

    return orb313_start_script($script, []);
}

/**
 * @param  list<string>  $arguments
 * @return array{process: Process, input: InputStream, port: int, files: list<string>}
 */
function orb313_start_script(string $script, array $arguments): array
{
    $scriptPath = tempnam(sys_get_temp_dir(), 'orbit-dns-script-');
    expect($scriptPath)->toBeString();
    file_put_contents($scriptPath, $script);
    $input = new InputStream;
    $process = new Process([PHP_BINARY, $scriptPath, ...$arguments], input: $input, timeout: 15);

    try {
        $process->start();
        $port = SubprocessFixture::line($process);
        expect(ctype_digit($port))->toBeTrue();
        expect((int) $port)->toBeGreaterThan(0);
    } catch (Throwable $exception) {
        $input->close();
        $process->stop();
        unlink($scriptPath);

        throw $exception;
    }

    return [
        'process' => $process,
        'input' => $input,
        'port' => (int) $port,
        'files' => [$scriptPath],
    ];
}

/**
 * @param  array{process: Process, input: InputStream, port: int, files: list<string>}  $started
 */
function orb313_stop_process(array $started): void
{
    $started['input']->close();
    $started['process']->stop();

    foreach ($started['files'] as $path) {
        @unlink($path);
    }
}
