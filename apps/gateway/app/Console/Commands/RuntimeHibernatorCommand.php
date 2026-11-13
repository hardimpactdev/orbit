<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Instances\Power\ContainerIdleSweep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

final class RuntimeHibernatorCommand extends Command
{
    public const int MinimumIdleSeconds = 60;

    #[\Override]
    protected $signature = 'orbit:runtime-hibernator
        {--dry-run : List what the container idle sleep would do; change nothing and skip the checkout sweep}
        {--instance=* : Only these container Instances, also while the container idle sleep is off}
        {--idle-seconds= : The idle window for this run, at least 60; only with --instance}';

    #[\Override]
    protected $description = 'Halt idle app-dev checkout Instance Processes, prune their reconstructable dependencies, and put idle development containers to sleep.';

    public function handle(SweepIdleAppDevRuntimesAction $sweep, ContainerIdleSweep $containers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = array_map(strval(...), (array) $this->option('instance'));
        $only = $ids === [] ? null : array_map(intval(...), array_values($ids));
        $idle = $this->option('idle-seconds');
        // A short window is for a named proof only: never "everything, a minute" by a typo.
        if ($idle !== null && ($only === null || ! ctype_digit((string) $idle) || (int) $idle < self::MinimumIdleSeconds)) {
            $this->error('--idle-seconds needs --instance and at least '.self::MinimumIdleSeconds.' seconds.');

            return self::INVALID;
        }

        $failed = false;
        if (! $dryRun && $only === null) {
            try {
                $result = $sweep->execute();
                $this->info("Halted [{$result->halted}] idle app-dev Instance runtime groups.");
                $this->info("Pruned [{$result->pruned}] cold app-dev Instance dependency trees.");
            } catch (HibernationException $exception) {
                $this->error($exception->getMessage());
                $failed = true;
            } catch (Throwable) {
                $this->error('Runtime hibernator failed.');
                $failed = true;
            }
        }

        // The container idle sleep acts only while the operator turned it on, or for the Instances a run names.
        $acts = ! $dryRun && ($only !== null || Config::boolean('orbit.hibernation.container_sleep'));
        if ($dryRun || $acts) {
            try {
                $decisions = $containers->run(! $acts, $only, $idle === null ? null : (int) $idle);
            } catch (Throwable) {
                $this->error('Container idle sleep failed.');

                return self::FAILURE;
            }
            $this->table(['Instance', 'Name', 'Decision', 'Reason', 'Operation'], array_map(static fn ($decision): array => [
                $decision->instanceId, $decision->name, $decision->decision, $decision->reason, $decision->operationId ?? '',
            ], $decisions));
            $sleeps = count(array_filter($decisions, static fn ($decision): bool => $decision->decision === 'sleep'));
            $this->info(($acts ? 'Put' : 'Would put')." [{$sleeps}] idle development containers to sleep.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
