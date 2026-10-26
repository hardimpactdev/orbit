<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use Closure;

/** Requests a native start, then checks unit state and the control-plane record claim. */
final readonly class SystemdGatewayReleaseUnitStarter implements GatewayReleaseUnitStarter
{
    /** @param  Closure(int): void|null  $sleep  waits the given milliseconds */
    public function __construct(
        private GatewayReleaseCommands $commands,
        private int $attempts = 10,
        private int $intervalMs = 500,
        private ?Closure $sleep = null,
    ) {}

    public function start(int $record, Closure $claimed): void
    {
        $this->commands->startRun($record);

        for ($attempt = 0; $attempt < $this->attempts; $attempt++) {
            $state = $this->commands->runState($record);

            if (in_array($state, ['active', 'activating', 'reloading', 'deactivating'], true) || $claimed()) {
                return;
            }

            if ($state === 'failed') {
                throw $this->failed($record, 'the unit failed');
            }

            ($this->sleep ?? static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            })($this->intervalMs);
        }

        if ($claimed()) {
            return;
        }

        throw $this->failed($record, 'the unit did not start');
    }

    public function isActive(int $record): bool
    {
        return in_array($this->commands->runState($record), ['active', 'activating', 'reloading', 'deactivating'], true);
    }

    private function failed(int $record, string $why): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'start',
            errorCode: 'gateway.release_unit_failed',
            message: "Release record {$record}: {$why}. Run orbit:gateway-web on the Gateway to install the release units, then check journalctl -u orbit-gateway-release-run@{$record}.service.",
            status: 503,
        );
    }
}
