<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Reads the headers of a topology's evidence log, which `bin/e2e-topology exec --record` and `logs --record` write:
 * `=== <start> <label> node=<node> exit=<code> duration=<ms>ms end=<end>`, in UTC with milliseconds. The reviewer's
 * approval of a subtask that declares a topology needs, for each declared node, an entry that exited 0 and started at
 * or after the last handoff.
 */
final readonly class TaskTopologyEvidence
{
    private const string Header = '/\A=== (\S+) .+ node=([a-z0-9-]+) exit=(-?\d+) duration=\d+ms end=\S+\z/D';

    /**
     * The declared nodes without such an entry, in declaration order.
     *
     * @param  list<string>  $nodes
     * @return list<string>
     */
    public static function missing(string $log, array $nodes, CarbonInterface $since): array
    {
        $proven = [];
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match(self::Header, $line, $match) !== 1 || $match[3] !== '0') {
                continue;
            }
            try {
                $started = CarbonImmutable::parse($match[1]);
            } catch (Throwable) {
                continue;
            }
            if ($started->greaterThanOrEqualTo($since)) {
                $proven[$match[2]] = true;
            }
        }

        return array_values(array_filter($nodes, static fn (string $node): bool => ! isset($proven[$node])));
    }

    /**
     * The rubric reminder for nodes without evidence.
     *
     * @param  list<string>  $missing
     */
    public static function reminder(int $groupId, array $missing): string
    {
        return 'This subtask declares a topology, and the evidence log has no recorded command that exited 0 since the last handoff on: '
            .implode(', ', $missing).'. Prove the deliverables there with `bin/e2e-topology exec TASK-'.$groupId.' NODE --argv=JSON --record=LABEL`, then approve again.';
    }
}
