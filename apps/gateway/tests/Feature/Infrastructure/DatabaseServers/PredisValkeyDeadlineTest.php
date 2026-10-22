<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseServers\PredisValkeyServerAdmin;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\DatabaseServer;
use App\Models\Node;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;

it('shares the probe deadline across authentication and response reads', function (bool $fragmented): void {
    $script = <<<'PHP'
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($listener, false)."\n";
        fflush(STDOUT);
        $socket = stream_socket_accept($listener, 5);
        function command($socket): array {
            $count = (int) substr(fgets($socket), 1);
            $parts = [];
            for ($i = 0; $i < $count; $i++) {
                $length = (int) substr(fgets($socket), 1);
                $parts[] = stream_get_contents($socket, $length);
                fread($socket, 2);
            }
            echo $parts[0]."\n";
            fflush(STDOUT);
            return $parts;
        }
        if (command($socket) !== ['HELLO', '2', 'AUTH', 'default', 'fixture-secret', 'SETNAME', 'predis']) { exit(1); }
        echo "authenticated\n";
        fflush(STDOUT);
        usleep(120000);
        fwrite($socket, "*2\r\n$2\r\nid\r\n:1\r\n");
        if (command($socket) !== ['CONFIG', 'GET', 'aclfile']) { exit(2); }
        if ($argv[1] === 'fragmented') {
            fwrite($socket, "*2\r\n$7\r\naclfile\r\n$20\r\n/var/");
        }
        usleep(200000);
        @fwrite($socket, $argv[1] === 'fragmented' ? "lib/valkey.conf\r\n" : "*2\r\n$7\r\naclfile\r\n$20\r\n/var/lib/valkey.conf\r\n");
        fclose($socket);
        PHP;
    $responder = new Process([PHP_BINARY, '-r', $script, $fragmented ? 'fragmented' : 'silent']);
    $responder->setTimeout(8)->start();

    try {
        $address = SubprocessFixture::line($responder);
        $server = new DatabaseServer(['slug' => 'deadline', 'port' => (int) substr($address, strrpos($address, ':') + 1), 'root_password' => 'fixture-secret']);
        $server->setRelation('node', new Node(['wireguard_ip' => '127.0.0.1']));
        $deadline = app(CommandDeadline::class);
        $started = microtime(true);

        expect(fn () => $deadline->within(0.2, fn () => app(PredisValkeyServerAdmin::class)->aclFile($server)))
            ->toThrow(ResourceOperationException::class, 'could not run an admin command');

        expect(microtime(true) - $started)->toBeLessThan(0.28);
        expect($responder->getOutput())->toContain("HELLO\nauthenticated\nCONFIG\n");
    } finally {
        $responder->stop();
    }
})->with(['silent response' => false, 'fragmented bulk response' => true]);

it('reads an authenticated Valkey response within the probe deadline', function (): void {
    $script = <<<'PHP'
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($listener, false)."\n";
        fflush(STDOUT);
        $socket = stream_socket_accept($listener, 5);
        fread($socket, 4096);
        fwrite($socket, "*2\r\n$2\r\nid\r\n:1\r\n");
        fread($socket, 4096);
        fwrite($socket, "*2\r\n$7\r\naclfile\r\n$20\r\n/var/lib/valkey.conf\r\n");
        fclose($socket);
        PHP;
    $responder = new Process([PHP_BINARY, '-r', $script]);
    $responder->setTimeout(8)->start();

    try {
        $address = SubprocessFixture::line($responder);
        $server = new DatabaseServer(['slug' => 'ready', 'port' => (int) substr($address, strrpos($address, ':') + 1), 'root_password' => 'fixture-secret']);
        $server->setRelation('node', new Node(['wireguard_ip' => '127.0.0.1']));

        $file = app(CommandDeadline::class)->within(0.2, fn () => app(PredisValkeyServerAdmin::class)->aclFile($server));

        expect($file)->toBe('/var/lib/valkey.conf');
    } finally {
        $responder->stop();
    }
});
