<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** The agent observes the operator hold; the Gateway verifies record identity and empty reservations. */
final readonly class SystemdGatewayReleaseDrain implements GatewayReleaseDrain
{
    public function __construct(
        private GatewayReleaseCommands $commands,
        private GatewayReleaseLayout $layout,
        private GatewayReleaseProcessDeclaration $declarations,
    ) {}

    public function assertHeld(string $targetSha): void
    {
        try {
            $this->check($targetSha);
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Redis and database errors can contain credentials. Keep their messages local.
            $this->refuse('A drain observation could not be read. Keep the hold and inspect locally.');
        }
    }

    private function check(string $targetSha): void
    {
        $callerPid = getmypid();
        if ($callerPid === false) {
            $this->refuse('The calling PHP process could not be identified.');
        }
        $hold = $this->commands->inspectHold($this->layout, $targetSha, $callerPid);
        $declarations = $hold['unrelated_processes'] ?? null;
        if (! is_array($declarations) || ! array_is_list($declarations)) {
            $this->refuse('The agent returned an incomplete hold observation.');
        }
        $seen = [];
        foreach ($declarations as $declaration) {
            if (! is_array($declaration) || ! is_int($declaration['process_id'] ?? null) || $declaration['process_id'] < 1) {
                $this->refuse('An unrelated Process declaration is malformed.');
            }
            $observed = $this->declarations->observe($targetSha, $declaration['process_id']);
            ksort($declaration);
            ksort($observed);
            if ($declaration !== $observed || in_array($observed['unit'], $seen, true)) {
                $this->refuse('An unrelated Process declaration is stale or duplicated.');
            }
            $seen[] = $observed['unit'];
        }
        if (config('queue.connections.gateway.driver') !== 'redis' || config('queue.connections.gateway.connection') !== 'gateway') {
            $this->refuse('The held release requires the supported Gateway Redis queue.');
        }
        foreach (GatewayQueue::cases() as $queue) {
            $reserved = Redis::connection('gateway')->zcard('queues:'.$queue->value.':reserved');
            if (! is_int($reserved) || $reserved !== 0) {
                $this->refuse("Queue [{$queue->value}] has reservations or could not be observed.");
            }
        }
    }

    private function refuse(string $message): never
    {
        throw new GatewayReleaseException(step: 'drain', errorCode: 'gateway.release_not_drained', message: $message);
    }
}
