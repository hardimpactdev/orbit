<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\SweepOperationsCommand;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationAttemptLease;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Models\Node;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Throwable;

/** The Gateway queue and Laravel Horizon (docs/reference/gateway-queue.md). */
final class GatewayQueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The lease of each operation attempt on the `operations` queue (docs/reference/operations.md).
        $this->app->singleton(
            OperationAttemptLease::class,
            static fn (): OperationAttemptLease => new OperationAttemptLease(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/locks/operations',
            ),
        );
    }

    public function boot(): void
    {
        $this->commands([
            SweepOperationsCommand::class,
        ]);
        // The dashboard admits the WireGuard peers with Gateway access, the same peers the Gateway API admits.
        Horizon::auth(static function (Request $request): bool {
            $peer = RequireActiveWireGuardPeer::activePeer($request);

            return $peer instanceof Node && app(NodeAccessAuthorizer::class)->hasGatewayAuthority($peer);
        });

        // The registered connection decides host and port, read once, before a process first uses Redis.
        // Every Artisan command resolves Redis for the Horizon commands, so a missing connection only logs here; a
        // dispatch then fails on the unresolvable host.
        $this->app->beforeResolving('redis', static function (mixed $abstract, array $parameters, Application $app): void {
            if ($app->resolved('redis') || ! GatewayQueueRedis::configured()) {
                return;
            }

            try {
                GatewayQueueRedis::configure();
            } catch (Throwable $exception) {
                Log::error('The Gateway queue has no Redis server.', ['error' => $exception->getMessage()]);
            }
        });
    }
}
