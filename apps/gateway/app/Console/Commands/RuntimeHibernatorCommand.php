<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Instances\Power\ContainerIdleDecision;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\ContainerSleepScope;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class RuntimeHibernatorCommand extends Command
{
    public const int MinimumIdleSeconds = 60;

    #[\Override]
    protected $signature = 'orbit:runtime-hibernator
        {--dry-run : List what the container idle sleep would do under each value of its setting; change nothing and skip the checkout sweep}
        {--instance=* : Only these container Instances, as under the value true, whatever the setting}
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

        // The setting names which containers the idle sleep acts on; a named run acts on its Instances as under `true`.
        try {
            $scope = ContainerSleepScope::configured();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($dryRun) {
            // A dry run never runs the checkout sweep.
            return $this->preview($containers, $scope, $only, $idle === null ? null : (int) $idle) ? self::SUCCESS : self::FAILURE;
        }
        $scope = $only === null ? $scope : ContainerSleepScope::All;
        if ($scope !== ContainerSleepScope::Off) {
            try {
                $decisions = $containers->run(false, $only, $idle === null ? null : (int) $idle, scope: $scope);
            } catch (Throwable) {
                $this->error('Container idle sleep failed.');

                return self::FAILURE;
            }
            $this->table(['Instance', 'Name', 'Decision', 'Reason', 'Watched since', 'Last HTTP', 'Last T3', 'Last wake', 'Last deploy', 'Operation'],
                array_map(static fn (ContainerIdleDecision $decision): array => [
                    $decision->instanceId, $decision->name, $decision->decision, $decision->reason, self::time($decision->watchStartedAt),
                    self::time($decision->lastHttpAt), self::agent($decision), self::time($decision->lastWakeAt), self::time($decision->lastDeployAt),
                    $decision->operationId ?? '',
                ], $decisions));
            $sleeps = count(array_filter($decisions, static fn ($decision): bool => $decision->decision === 'sleep'));
            $this->info("Put [{$sleeps}] idle development containers to sleep.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The dry run: each row's decision under each value of the setting, and the evidence read once for all of them.
     *
     * @param  list<int>|null  $only
     */
    private function preview(ContainerIdleSweep $containers, ContainerSleepScope $scope, ?array $only, ?int $idle): bool
    {
        try {
            $preview = $containers->preview($only, $idle);
        } catch (Throwable) {
            $this->error('Container idle sleep failed.');

            return false;
        }
        $this->info("ORBIT_HIBERNATION_CONTAINER_SLEEP is [{$scope->setting()}].");
        $cell = static fn (ContainerIdleDecision $decision): string => $decision->decision === 'sleep' ? 'sleep' : 'skip: '.$decision->reason;
        $rows = [];
        foreach ($preview[ContainerSleepScope::All->value] as $index => $decision) {
            $rows[] = [$decision->instanceId, $decision->name,
                ...array_map(static fn (ContainerSleepScope $value): string => $cell($preview[$value->value][$index]), ContainerSleepScope::cases()),
                self::time($decision->watchStartedAt), self::time($decision->lastHttpAt), self::agent($decision),
                self::time($decision->lastWakeAt), self::time($decision->lastDeployAt)];
        }
        $this->table(['Instance', 'Name', ...array_map(static fn (ContainerSleepScope $value): string => $value->setting(), ContainerSleepScope::cases()),
            'Watched since', 'Last HTTP', 'Last T3', 'Last wake', 'Last deploy'], $rows);
        $sleeps = count(array_filter($preview[$scope->value], static fn (ContainerIdleDecision $decision): bool => $decision->decision === 'sleep'));
        $this->info("Would put [{$sleeps}] idle development containers to sleep.");

        return true;
    }

    /** A time of the evidence in UTC to the second, or `-` when the sweep did not read one or found none. */
    private static function time(?int $unix): string
    {
        return $unix === null ? '-' : gmdate('Y-m-d\\TH:i:s\\Z', $unix);
    }

    private static function agent(ContainerIdleDecision $decision): string
    {
        $t3 = $decision->t3;

        return match (true) {
            ! $decision->t3Server => 'no T3 server',
            $t3 === null => '-',
            ! $t3->readable => 'unreadable',
            $t3->turnRuns => 'turn running',
            default => self::time($t3->lastActivityUnix),
        };
    }
}
