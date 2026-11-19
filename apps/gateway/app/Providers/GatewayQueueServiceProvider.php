<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Infrastructure\Gateway\GatewayHorizonReservation;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Models\Node;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Listeners\MarkJobAsReserved;
use Laravel\Octane\Events\WorkerStarting;
use Throwable;

/** The Gateway queue and Laravel Horizon (docs/reference/gateway-queue.md). */
final class GatewayQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MarkJobAsReserved::class, GatewayHorizonReservation::class);

    }

    public function boot(): void
    {
        // The dashboard admits the WireGuard peers with Gateway access, the same peers the Gateway API admits.
        Horizon::auth(static function (Request $request): bool {
            $peer = RequireActiveWireGuardPeer::activePeer($request);

            return $peer instanceof Node && app(NodeAccessAuthorizer::class)->hasGatewayAuthority($peer);
        });

        // The registered connection decides host and port, read once, before a process first uses Redis.
        // Every Artisan command resolves Redis for the Horizon commands, so a missing connection only logs here; a
        // dispatch then fails on the unresolvable host. The hook writes to the configuration of the container that
        // resolves Redis: Horizon's queue connector resolves it on an Octane worker's application, not the request's.
        $this->app->beforeResolving('redis', static function (mixed $abstract, array $parameters, Application $app): void {
            if (! $app->resolved('redis')) {
                self::configureQueue($app);
            }
        });

        // An Octane worker configures its application once when it starts, so every request copy inherits the host.
        Event::listen(WorkerStarting::class, static function (WorkerStarting $event): void {
            self::configureQueue($event->app);
        });
    }

    private static function configureQueue(Application $app): void
    {
        $config = $app->make('config');

        if (! GatewayQueueRedis::configured($config) || GatewayQueueRedis::applied($config)) {
            return;
        }

        try {
            GatewayQueueRedis::configure($config);
        } catch (Throwable $exception) {
            Log::error('The Gateway queue has no Redis server.', ['error' => $exception->getMessage()]);
        }
    }
}
