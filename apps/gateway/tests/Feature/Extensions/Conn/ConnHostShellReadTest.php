<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Models\Node;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\T3AdminSession;
use Orbit\Extensions\Conn\Domain\T3EnvironmentDescriptor;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use Orbit\Extensions\Conn\Infrastructure\HttpT3ServerClient;
use Orbit\Extensions\Conn\Jobs\PollConnHostThreadsJob;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
});

/** A host shell of `$count` threads, as `GET /api/orchestration/shell` answers it. */
function hostShell(int $count): string
{
    $threads = array_map(static fn (int $n): array => ['id' => "thread-{$n}", 'title' => "Thread {$n} ".str_repeat('x', 200),
        'status' => 'idle', 'lineage' => ['parentThreadId' => null]], range(1, $count));

    return json_encode(['projects' => [], 'threads' => $threads], JSON_THROW_ON_ERROR);
}

/**
 * What a pooled request gets when curl times out after the headers: Guzzle rejects with the half body it holds, and the transfer stats
 * carry curl's error 28 (CURLE_OPERATION_TIMEOUTED), as the curl handler reports them before it rejects.
 *
 * @param  array<string, mixed>  $options
 */
function cutOffBody(Request $request, array $options, string $body): PromiseInterface
{
    $psrRequest = $request->toPsrRequest();
    $partial = new Response(200, ['Content-Type' => 'application/json'], substr($body, 0, intdiv(strlen($body), 2)));
    $options['on_stats'](new TransferStats($psrRequest, $partial, null, 28));

    return Create::rejectionFor(new ResponseTimeoutException('Operation timed out after 6000 milliseconds', $psrRequest, $partial));
}

/** A host server registered on its own Node. */
function hostServer(string $name, string $url): ConnEnvironment
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => $name.'.test', 'wireguard_ip' => '10.44.0.'.random_int(10, 200), 'user' => 'orbit']));

    return ConnEnvironment::query()->create(['environment_id' => 'env-'.$name, 'label' => $name, 'url' => $url, 'node_id' => $node->id,
        'admin_session' => 'session-'.$name, 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
}

describe('a body cut off by a timeout', function (): void {
    it('is an unreachable server in shells(), and a complete large shell still reads', function (): void {
        $shell = hostShell(1704);
        Http::fake([
            'http://mini.test:3773/*' => fn (Request $request, array $options) => cutOffBody($request, $options, $shell),
            'http://nick.test:3773/*' => Http::response($shell),
        ]);

        $shells = app(HttpT3ServerClient::class)->shells([
            'mini' => ['url' => 'http://mini.test:3773', 'session' => 'session-mini'],
            'nick' => ['url' => 'http://nick.test:3773', 'session' => 'session-nick'],
        ], 6.0);

        expect($shells['mini'])->toBeInstanceOf(T3ServerException::class)
            ->and($shells['mini']->errorCode)->toBe('conn.server_unreachable')
            ->and($shells['nick']['threads'])->toHaveCount(1704);
    });

    it('is an unreachable server in describes(), and a complete answer still reads', function (): void {
        $describe = json_encode(['environmentId' => 'env-mini', 'label' => 'mini', 'serverVersion' => '0.5.2',
            'padding' => str_repeat('x', 100_000)], JSON_THROW_ON_ERROR);
        Http::fake([
            'http://mini.test:3773/*' => fn (Request $request, array $options) => cutOffBody($request, $options, $describe),
            'http://nick.test:3773/*' => Http::response($describe),
        ]);

        $descriptors = app(HttpT3ServerClient::class)->describes(['mini' => 'http://mini.test:3773', 'nick' => 'http://nick.test:3773'], 6.0);

        expect($descriptors['mini'])->toBeInstanceOf(T3ServerException::class)
            ->and($descriptors['mini']->errorCode)->toBe('conn.server_unreachable')
            ->and($descriptors['nick'])->toBeInstanceOf(T3EnvironmentDescriptor::class)
            ->and($descriptors['nick']->environmentId)->toBe('env-mini');
    });
});

describe('gzip', function (): void {
    it('asks for a gzip shell and decodes it through the real curl handler', function (): void {
        // A local server that answers a gzip body only to a request that asks for gzip, as a Conn Server with compression would.
        $script = <<<'PHP'
$body = stream_get_contents(STDIN);
$server = stream_socket_server('tcp://127.0.0.1:0');
echo stream_socket_get_name($server, false)."\n";
$conn = stream_socket_accept($server, 2);
$raw = '';
while (! str_contains($raw, "\r\n\r\n") && ($chunk = fread($conn, 4096)) !== false && $chunk !== '') {
    $raw .= $chunk;
}
$asked = preg_match('/^accept-encoding:\s*gzip\s*$/mi', $raw) === 1;
$response = $asked ? gzencode($body) : '{"reason":"no gzip asked"}';
fwrite($conn, ($asked ? "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\n" : "HTTP/1.1 400 Bad Request\r\n")
    ."Content-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n".$response);
fclose($conn);
PHP;
        // Under the 64 KB pipe buffer, so the write never waits on the server; the server and the read each wait at most 2 seconds.
        $shell = hostShell(200);
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert(is_resource($process));

        try {
            fwrite($pipes[0], $shell);
            fclose($pipes[0]);
            $address = trim((string) fgets($pipes[1]));
            Http::allowStrayRequests(['http://'.$address.'/*']);
            $shells = app(HttpT3ServerClient::class)->shells(['mini' => ['url' => 'http://'.$address, 'session' => 'session-mini']], 2.0);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        expect($shells['mini'])->toBe(json_decode($shell, true));
    });
});

describe('the host poll', function (): void {
    it('reads host servers within six seconds', function (): void {
        hostServer('mini', 'http://mini.test:3773');
        $client = new class implements T3ServerClient
        {
            public ?float $timeout = null;

            public function describes(array $baseUrls, float $timeoutSeconds): array
            {
                throw new LogicException('unused');
            }

            public function describe(string $baseUrl): T3EnvironmentDescriptor
            {
                throw new LogicException('unused');
            }

            public function session(string $baseUrl, string $adminSession): T3AdminSession
            {
                throw new LogicException('unused');
            }

            public function shell(string $baseUrl, string $adminSession): array
            {
                throw new LogicException('unused');
            }

            public function shells(array $servers, float $timeoutSeconds): array
            {
                $this->timeout = $timeoutSeconds;

                return [];
            }
        };
        app()->instance(T3ServerClient::class, $client);

        app()->call([new PollConnHostThreadsJob, 'handle']);

        expect($client->timeout)->toBe(6.0);
    });

    it('records a cut-off host as unreachable while the other hosts read', function (): void {
        $mini = hostServer('mini', 'http://mini.test:3773');
        $nick = hostServer('nick', 'http://nick.test:3773');
        $shell = hostShell(1704);
        Http::fake([
            'http://mini.test:3773/*' => fn (Request $request, array $options) => cutOffBody($request, $options, $shell),
            'http://nick.test:3773/*' => Http::response($shell),
        ]);

        app()->call([new PollConnHostThreadsJob, 'handle']);

        expect($mini->refresh()->threads_read_error)->toBe('conn.server_unreachable')
            ->and($nick->refresh()->threads_read_error)->toBeNull()
            ->and(ConnThread::forEnvironment($nick)->count())->toBe(1704)
            ->and(ConnThread::forEnvironment($mini)->count())->toBe(0);
    });
});
