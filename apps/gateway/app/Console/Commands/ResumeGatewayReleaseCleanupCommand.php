<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;

/**
 * The release run's cleanup steps. A scheduler start pauses each extension's release cleanup under a new generation,
 * so the switch step reads the generations first, and after verify this command waits for the new ones, reconciles
 * and resumes. A cleanup that cannot resume exits 1 and stays paused; the release itself is healthy.
 */
final class ResumeGatewayReleaseCleanupCommand extends GatewayReleaseCommand
{
    #[\Override]
    protected $signature = 'gateway:release:cleanup
        {--before= : The generations this command printed before the scheduler restart, as JSON; without it, prints them}';

    #[\Override]
    protected $description = 'Read the release cleanup generations, or resume the release cleanups after a scheduler restart.';

    public function handle(GatewayCleanupHandoff $cleanup): int
    {
        $before = $this->option('before');
        if (! is_string($before)) {
            return $this->report(fn (): array => ['generations' => $cleanup->generation()]);
        }
        $paused = false;
        $status = $this->report(function () use ($cleanup, $before, &$paused): array {
            $generations = json_decode($before, true);
            if (! is_array($generations) || ! array_is_list($generations)
                || array_filter($generations, static fn (mixed $generation): bool => $generation !== null && ! is_string($generation)) !== []) {
                throw new GatewayReleaseException('cleanup', 'gateway.release_cleanup_generations_invalid', 'The cleanup generations must be a JSON list.', 422);
            }
            $result = $cleanup->resume($generations, true);
            $paused = $result['paused'];

            return $result;
        });

        return $status === self::SUCCESS && $paused ? self::FAILURE : $status;
    }
}
