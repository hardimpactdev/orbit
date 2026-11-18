<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Broadcasting\RealtimeConnectionData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Broadcasting\ReverbBroadcaster;
use App\Infrastructure\Logging\RequestLogContext;
use App\Models\Activity;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as ReverbResponse;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Monolog\Handler\TestHandler;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

describe('a persistent Gateway worker', function (): void {
    it('releases a completed activity before PHP shuts the worker down', function (): void {
        $activity = (new Activity)->forceFill(['id' => 1]);
        $guard = ActivityShutdownFinalizer::arm($activity);
        $reference = WeakReference::create($guard);
        $guard->disarm();
        unset($guard);

        expect($reference->get())->toBeNull();
    });

    it('rolls back a lost transaction and clears caller context before another request', function (bool $failed, bool $raw): void {
        $db = DB::connection();
        $db->statement('CREATE TABLE worker_write (value INTEGER)');
        if ($raw) {
            $db->getPdo()->beginTransaction();
        } else {
            $db->beginTransaction();
            $db->beginTransaction();
        }
        $db->table('worker_write')->insert(['value' => 1]);
        RequestLogContext::enter('first-peer');

        app()->instance(Client::class, new FrankenPhpClient);
        $event = $failed
            ? new WorkerErrorOccurred(new RuntimeException('test'), app())
            : new RequestTerminated(app(), app(), Request::create('/up'), new Response);
        Event::dispatch($event);

        expect($db->transactionLevel())->toBe(0)
            ->and($db->getPdo()->inTransaction())->toBeFalse()
            ->and($db->table('worker_write')->count())->toBe(0)
            ->and(RequestLogContext::enter('second-peer'))->toBeNull();

        RequestLogContext::clear();
    })->with([[false, false], [true, false], [false, true]]);

    it('gives each request its own pending task notices', function (): void {
        $first = app(TaskBroadcasts::class);
        app()->forgetScopedInstances();

        expect(app(TaskBroadcasts::class))->not->toBe($first);
    });

    it('delivers each record once and releases the Reverb signing driver at the worker request boundary', function (bool $terminationFails): void {
        $recipients = [
            'original' => new WebSocketCredentials('first-app', 'first-key', bin2hex(random_bytes(16)), '127.0.0.11'),
            'rotated-secret' => new WebSocketCredentials('first-app', 'first-key', bin2hex(random_bytes(16)), '127.0.0.11'),
            'replacement' => new WebSocketCredentials('next-app', 'next-key', bin2hex(random_bytes(16)), '127.0.0.12'),
        ];
        $credentials = Mockery::mock(WebSocketCredentialManager::class);
        $credentials->shouldReceive('current')->times(4)->andReturn($recipients['original'], $recipients['rotated-secret'], $recipients['replacement'], null);
        app()->instance(WebSocketCredentialManager::class, $credentials);

        $received = [];
        $secretExposed = false;
        // Let the real broadcaster and Pusher client build and sign the request. Stand in only for Reverb's HTTP receiver.
        $receive = static function (RequestInterface $request, array $options) use ($recipients, &$received, &$secretExposed): PromiseInterface {
            $uri = $request->getUri();
            $body = (string) $request->getBody();
            parse_str($uri->getQuery(), $query);
            $signature = $query['auth_signature'] ?? '';
            unset($query['auth_signature']);
            ksort($query);
            $signed = $request->getMethod()."\n".$uri->getPath()."\n".http_build_query($query);
            $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $wire = (string) $uri.$body.json_encode($request->getHeaders(), JSON_THROW_ON_ERROR);

            foreach ($recipients as $name => $recipient) {
                $secretExposed = $secretExposed || str_contains($wire, $recipient->appSecret);
                if ($request->getMethod() === 'POST'
                    && $uri->getScheme() === 'https'
                    && $uri->getHost() === 'reverb.orbit'
                    && $uri->getPath() === "/apps/{$recipient->appId}/events"
                    && ($options['curl'][CURLOPT_RESOLVE] ?? null) === ["reverb.orbit:443:{$recipient->servingAddress}"]
                    && ($query['auth_key'] ?? null) === $recipient->appKey
                    && hash_equals(hash_hmac('sha256', $signed, $recipient->appSecret), $signature)) {
                    $received[$name][] = [$event['name'], $event['channels'], json_decode($event['data'], true, flags: JSON_THROW_ON_ERROR)['id']];

                    return new FulfilledPromise(new ReverbResponse(200, [], '{}'));
                }
            }

            return new FulfilledPromise(new ReverbResponse(401, [], 'Unexpected broadcast recipient.'));
        };

        $manager = app(BroadcastManager::class);
        $manager->extend('reverb', function ($app, array $config) use ($receive): ReverbBroadcaster {
            $config['client_options']['handler'] = $receive;

            return new ReverbBroadcaster($this->pusher($config), (bool) ($config['jsonp'] ?? false));
        });
        $logs = new TestHandler;
        Log::driver()->getLogger()->pushHandler($logs);
        $workerErrors = [];
        Event::listen(WorkerErrorOccurred::class, static function (WorkerErrorOccurred $event) use (&$workerErrors): void {
            $workerErrors[] = $event->exception->getMessage();
        });
        $drivers = new ReflectionProperty(BroadcastManager::class, 'drivers');
        $cachedOnEntry = [];
        app('router')->post('/worker-record/{id}', static function (int $id) use ($drivers, $terminationFails, &$cachedOnEntry): Response {
            $cachedOnEntry[] = isset($drivers->getValue(app(BroadcastManager::class))['reverb']);
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::OperationCreated, $id, []);
            if ($terminationFails && $id === 11) {
                app()->terminating(static function (): void {
                    throw new RuntimeException('Synthetic failure after the deferred Reverb send.');
                });
            }

            return new Response(null, 204);
        });

        $client = Mockery::mock(Client::class);
        $responseDeliveryCounts = [];
        $client->shouldReceive('respond')->times(4)->withArgs(static fn (RequestContext $context, OctaneResponse $response): bool => $response->response->getStatusCode() === 204
            && $response->response->getContent() === '' && $response->outputBuffer === '')
            ->andReturnUsing(static function () use (&$received, &$responseDeliveryCounts): void {
                $responseDeliveryCounts[] = array_sum(array_map(count(...), $received));
            });
        $client->shouldNotReceive('error');
        app()->instance(Client::class, $client);
        $factory = Mockery::mock(ApplicationFactory::class);
        $factory->shouldReceive('createApplication')->once()->andReturn(app());
        $worker = new Worker($factory, $client);
        $worker->boot();

        $cachedAfterRequest = [];
        foreach ([11, 12, 13, 14] as $id) {
            $worker->handle(Request::create("/worker-record/$id", 'POST'), new RequestContext);
            $cachedAfterRequest[] = isset($drivers->getValue($manager)['reverb']);
        }

        expect($received)->toBe([
            'original' => [['operation.created', ['private-orbit'], 11]],
            'rotated-secret' => [['operation.created', ['private-orbit'], 12]],
            'replacement' => [['operation.created', ['private-orbit'], 13]],
        ])->and($secretExposed)->toBeFalse();
        expect($responseDeliveryCounts)->toBe([0, 1, 2, 3]);
        expect($workerErrors)->toBe($terminationFails ? ['Synthetic failure after the deferred Reverb send.'] : []);
        expect($cachedOnEntry)->toBe([false, false, false, false]);
        expect($cachedAfterRequest)->toBe([false, false, false, false]);
        expect($logs->hasWarningThatContains('Failed to broadcast a record event.'))->toBeFalse();
        $logText = json_encode($logs->getRecords(), JSON_THROW_ON_ERROR);
        foreach ($recipients as $recipient) {
            expect(str_contains($logText, $recipient->appSecret))->toBeFalse();
        }
    })->with(['normal termination' => [false], 'termination callback throws' => [true]]);

    it('reuses the Reverb connection only for the same endpoint and trusted CA', function (): void {
        $authority = tempnam(sys_get_temp_dir(), 'worker-ca-');
        $connection = app(RealtimeConnection::class);
        $handler = static function (string $address, string $secret = 'secret') use ($authority, $connection): mixed {
            $connection->configureBroadcasting(new RealtimeConnectionData(
                host: 'reverb.test', port: 443, scheme: 'https', appId: 'app', key: 'key',
                secret: $secret, caCertificatePath: $authority, resolveAddress: $address,
            ));

            return config('broadcasting.connections.reverb.client_options.handler');
        };

        try {
            file_put_contents($authority, 'first test authority');
            $first = $handler('192.0.2.1');
            expect($handler('192.0.2.1'))->toBe($first)
                ->and($handler('192.0.2.1', 'rotated secret'))->toBe($first)
                ->and($handler('192.0.2.2'))->not->toBe($first);
            $beforeRotation = $handler('192.0.2.1');
            file_put_contents($authority, 'second test authority');
            expect($handler('192.0.2.1'))->not->toBe($beforeRotation);
        } finally {
            unlink($authority);
        }
    });
});
