<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseSwampLoad;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use Closure;

/**
 * Records one `gateway.swamp_load` intent for the release commit and waits for its native terminal. Swamp checks the
 * commit out in the serve checkout on Node 209 and confirms that serve loaded it. Anything else stops the release
 * before the switch, so the old release stays live. Serve's reload also re-reads each extension manifest's
 * additionalFiles, so a newly shipped file needs no serve restart.
 */
final readonly class NativeGatewayReleaseSwampLoad implements GatewayReleaseSwampLoad
{
    /** The single Swamp Node. */
    public const int SwampNode = 209;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $sleep  microseconds
     * @param  (Closure(): float)|null  $clock  monotonic nanoseconds
     */
    public function __construct(private StartOperationAction $operations, private int $waitSeconds = 600, ?Closure $sleep = null, ?Closure $clock = null)
    {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true);
    }

    public function load(string $sha): array
    {
        $release = GatewayReleaseLayout::fromConfig()->releasePath(substr($sha, 0, 12));
        if (! is_file($release.'/REVISION') || trim((string) file_get_contents($release.'/REVISION')) !== $sha) {
            throw new GatewayReleaseException('swamp', 'gateway.release_swamp_load_failed', 'Swamp grants require the prepared release commit.', sha: $sha);
        }
        $input = ['sha' => $sha, 'grants' => (new SwampGrantRenderer)->render($release.'/swamp'),
            'ssh' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail(self::SwampNode))];
        $operation = $this->run($sha, $input);

        return ['outcome' => 'loaded', 'operation_id' => $operation->id];
    }

    /** @param array<string, mixed> $input */
    private function run(string $sha, array $input): Operation
    {
        try {
            $operation = $this->operations->execute('gateway.swamp_load', 'node', self::SwampNode, self::SwampNode, $input, 'gateway-release', null);
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
