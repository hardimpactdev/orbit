<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Broadcasting\RealtimeConnectionData;
use App\Domain\Tasks\TaskBroadcasts;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Logging\RequestLogContext;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
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

    it('reuses the Reverb connection only for the same endpoint and trusted CA', function (): void {
        $authority = tempnam(sys_get_temp_dir(), 'worker-ca-');
        $connection = app(RealtimeConnection::class);
        $handler = static function (string $address) use ($authority, $connection): mixed {
            $connection->configureBroadcasting(new RealtimeConnectionData(
                host: 'reverb.test', port: 443, scheme: 'https', appId: 'app', key: 'key',
                secret: 'secret', caCertificatePath: $authority, resolveAddress: $address,
            ));

            return config('broadcasting.connections.reverb.client_options.handler');
        };

        try {
            file_put_contents($authority, 'first test authority');
            $first = $handler('192.0.2.1');
            expect($handler('192.0.2.1'))->toBe($first)
                ->and($handler('192.0.2.2'))->not->toBe($first);
            $beforeRotation = $handler('192.0.2.1');
            file_put_contents($authority, 'second test authority');
            expect($handler('192.0.2.1'))->not->toBe($beforeRotation);
        } finally {
            unlink($authority);
        }
    });
});
