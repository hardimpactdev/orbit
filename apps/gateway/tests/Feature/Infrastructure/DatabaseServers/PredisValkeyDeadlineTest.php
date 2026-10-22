<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseServers\PredisValkeyServerAdmin;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\DatabaseServer;
use App\Models\Node;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;
use Tests\Support\ValkeyDeadlineClock;

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
        fwrite($socket, "*2\r\n$2\r\nid\r\n:1\r\n");
        if (command($socket) !== ['CONFIG', 'GET', 'aclfile']) { exit(2); }
        if ($argv[1] === 'fragmented') {
            fwrite($socket, "*2\r\n$7\r\naclfile\r\n$20\r\n/var/");
        }
        // Keep the silent/incomplete response open until the real client disconnects on expiry.
        stream_get_contents($socket);
        fclose($socket);
        PHP;
    $responder = new Process([PHP_BINARY, '-r', $script, $fragmented ? 'fragmented' : 'silent']);
    $responder->setTimeout(8)->start();

    try {
        $address = SubprocessFixture::line($responder);
        $server = new DatabaseServer(['slug' => 'deadline', 'port' => (int) substr($address, strrpos($address, ':') + 1), 'root_password' => 'fixture-secret']);
        $server->setRelation('node', new Node(['wireguard_ip' => '127.0.0.1']));
        $clock = new ValkeyDeadlineClock($fragmented);
        $deadline = new CommandDeadline($clock->now(...));
        app()->instance(CommandDeadline::class, $deadline);
        $started = $clock->time;

        $clock->run(function () use ($deadline, $server): void {
            expect(fn () => $deadline->within(0.2, fn () => app(PredisValkeyServerAdmin::class)->aclFile($server)))
                ->toThrow(ResourceOperationException::class, 'could not run an admin command');
        });

        // Judge the unchanged bound against the protocol clock, not host scheduling or real sleeps.
        expect($clock->time - $started)->toBeLessThan(0.28);
        expect($clock->authenticated)->toBeTrue()
            ->and($clock->configured)->toBeTrue()
            ->and($clock->fragment)->toBe($fragmented ? '/var/' : '')
            ->and($clock->expiryChecks)->toBe(1)
            ->and($clock->readsAfterExpiry)->toBe(0)
            ->and(array_values(array_unique($clock->waitBudgets)))->toBe([200_000, 80_000])
            ->and($clock->waitSettingsApplied)->toBeTrue();
        $responder->wait();
        expect($responder->getExitCode())->toBe(0, $responder->getErrorOutput());
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

        $deadline = new CommandDeadline(static fn (): float => 0.0);
        app()->instance(CommandDeadline::class, $deadline);
        $file = $deadline->within(0.2, fn () => app(PredisValkeyServerAdmin::class)->aclFile($server));

        expect($file)->toBe('/var/lib/valkey.conf');
    } finally {
        $responder->stop();
    }
});
