<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseSwampLoad;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Records one `gateway.swamp_load` intent for the release commit and waits for its native terminal. Swamp checks the
 * commit out in the serve checkout on Node 209 and confirms that serve loaded it. Anything else stops the release
 * before the switch, so the old release stays live.
 *
 * When the commit's manifest additionalFiles differ from those serve started with, the load schedules a serve restart
 * instead, because Swamp's extension reload keeps the lists from serve's start (https://swamp-club.com/lab/4282). Serve
 * drains its runs before it stops. The release waits for a new serve instance and loads again. Remove the restart path
 * when that issue is fixed.
 */
final readonly class NativeGatewayReleaseSwampLoad implements GatewayReleaseSwampLoad
{
    /** The single Swamp Node. */
    public const int SwampNode = 209;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(): ?string the serving instance from serve's /ready, or null while it is unavailable */
    private Closure $instance;

    /**
     * @param  (Closure(int): void)|null  $sleep  microseconds
     * @param  (Closure(): float)|null  $clock  monotonic nanoseconds
     * @param  (Closure(): ?string)|null  $instance
     */
    public function __construct(private StartOperationAction $operations, private int $waitSeconds = 600, ?Closure $sleep = null, ?Closure $clock = null, ?Closure $instance = null)
    {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true);
        $this->instance = $instance ?? static function (): ?string {
            try {
                $address = config('swamp.address');
                if (! is_string($address)) {
                    return null;
                }
                $instance = Http::withOptions(['verify' => config('swamp.ca_path'), 'curl' => [CURLOPT_RESOLVE => ["swamp.orbit:443:{$address}"]]])
                    ->timeout(5)->get('https://swamp.orbit/ready')->json('instanceId');

                return is_string($instance) ? $instance : null;
            } catch (Throwable) {
                return null;
            }
        };
    }

    public function load(string $sha): array
    {
        $served = ($this->instance)();
        $first = $this->run($sha);
        $restart = $first->result['restart'] ?? null;
        if (! is_array($restart)) {
            return ['outcome' => 'loaded', 'operation_id' => $first->id];
        }

        $restarted = ($this->clock)();
        $instance = $this->awaitNewInstance($sha, $served);
        $restart = [...$restart, 'operation_id' => $first->id, 'instance' => $instance,
            'waited_seconds' => (int) round((($this->clock)() - $restarted) / 1_000_000_000)];
        $second = $this->run($sha);

        return ['outcome' => 'loaded', 'operation_id' => $second->id, 'restart' => $restart];
    }

    /** Serve drains for up to 5 minutes before it stops, so its new instance may take that long to answer. */
    private function awaitNewInstance(string $sha, ?string $previous): string
    {
        $deadline = ($this->clock)() + 420 * 1_000_000_000;
        while (($current = ($this->instance)()) === null || $current === $previous) {
            if (($this->clock)() >= $deadline) {
                throw new GatewayReleaseException('swamp', 'gateway.release_swamp_load_failed', "Swamp serve did not come back after the restart for {$sha} within 420 seconds.", sha: $sha);
            }
            ($this->sleep)(1_000_000);
        }

        return $current;
    }

    private function run(string $sha): Operation
    {
        try {
            $operation = $this->operations->execute('gateway.swamp_load', 'node', self::SwampNode, self::SwampNode, ['sha' => $sha], 'gateway-release', null);
        } catch (ResourceOperationException $exception) {
            throw new GatewayReleaseException('swamp', 'gateway.release_swamp_load_failed', "Swamp did not start the load of {$sha}: {$exception->errorCode}.", previous: $exception, sha: $sha);
        }

        $deadline = ($this->clock)() + $this->waitSeconds * 1_000_000_000;
        while (! $operation->refresh()->status->terminal()) {
            if (($this->clock)() >= $deadline) {
                throw new GatewayReleaseException('swamp', 'gateway.release_swamp_load_failed', "Swamp did not finish the load of {$sha} within {$this->waitSeconds} seconds (Operation {$operation->id}).", sha: $sha);
            }
            ($this->sleep)(1_000_000);
        }

        if ($operation->status !== OperationStatus::Succeeded) {
            throw new GatewayReleaseException('swamp', 'gateway.release_swamp_load_failed',
                "Swamp could not load {$sha}: Operation {$operation->id} ended {$operation->status->value}".($operation->error_code !== null ? " with {$operation->error_code}." : '.'), sha: $sha);
        }

        return $operation;
    }
}
