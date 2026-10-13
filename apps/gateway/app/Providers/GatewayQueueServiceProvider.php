<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayHorizonUnit;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Models\Node;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\MasterSupervisor;
use Throwable;

/** The Gateway queue and Laravel Horizon (docs/reference/gateway-queue.md). */
final class GatewayQueueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The dashboard admits the WireGuard peers with Gateway access, the same peers the Gateway API admits.
        Horizon::auth(static function (Request $request): bool {
            $peer = RequireActiveWireGuardPeer::activePeer($request);

            return $peer instanceof Node && app(NodeAccessAuthorizer::class)->hasGatewayAuthority($peer);
        });

        if (GatewayQueueRedis::configured()) {
            // The registered connection decides host and port, read once, before a process first uses Redis.
            // Every Artisan command resolves Redis for the Horizon commands, so a missing connection only logs here; a
            // dispatch then fails on the unresolvable host.
            $this->app->beforeResolving('redis', static function (mixed $abstract, array $parameters, Application $app): void {
                if ($app->resolved('redis')) {
                    return;
                }

                try {
                    GatewayQueueRedis::configure();
                } catch (Throwable $exception) {
                    Log::error('The Gateway queue has no Redis server.', ['error' => $exception->getMessage()]);
                }
            });
        }

        // A Horizon master keeps the code of the release it started on. When the stable path switches back to a release
        // without Horizon, it terminates, and systemd does not start it again. A move to a release with Horizon is left to
        // the handoff (GatewayHorizonUnit), which keeps the task-vms queue paused until the old worker is done.
        $release = realpath(base_path()) ?: base_path();
        Event::listen(MasterSupervisorLooped::class, static function (MasterSupervisorLooped $event) use ($release): void {
            self::watchRelease($event->master, $release);
        });
    }

    /** Terminates the master when the stable path links to a release other than its own, and that release has no Horizon. */
    public static function watchRelease(MasterSupervisor $master, string $release): void
    {
        // realpath() caches link targets per process; the master runs for days.
        clearstatcache(true);
        $current = realpath(GatewayApplicationPath::resolve());

        if ($master->working && $current !== false && $current !== $release && ! is_file($current.'/'.GatewayHorizonUnit::Marker)) {
            $master->terminate();
        }
    }
}
