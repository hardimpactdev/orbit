<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * The records of a topology's evidence log that count for an approval. `bin/e2e-topology exec --record` and
 * `logs --record` append one entry each: a blank line or the start of the file, then
 * `=== <start> <label> node=<node> exit=<code> duration=<ms>ms end=<end>` in UTC with milliseconds, the argv as
 * `$ <shell words>`, and `--- stdout`. Only that shape counts, so a header inside command output, which is not
 * preceded by a blank line and followed by those two lines, cannot forge a record.
 *
 * A record counts when its command exited 0, started at or after the last handoff and not after now, and did work on
 * its node: a command that only exits, such as `true` or `:`, proves nothing.
 */
final readonly class TaskTopologyEvidence
{
    private const string Header = '/\A=== (\S+) (.+) node=([a-z0-9-]+) exit=(-?\d+) duration=\d+ms end=\S+\z/D';

    /** Programs that do no work on the node. */
    private const array NoOps = ['true', ':', 'false', 'exit', 'echo', 'printf', 'sleep', 'yes'];

    private const int SummaryLimit = 20;

    /** @param list<array{start: string, label: string, node: string, command: string}> $records */
    private function __construct(public array $records) {}

    public static function parse(string $log, CarbonInterface $since, CarbonInterface $now): self
    {
        $lines = preg_split('/\R/', $log) ?: [];
        $records = [];
        foreach ($lines as $i => $line) {
            if (preg_match(self::Header, $line, $match) !== 1 || $match[4] !== '0') {
                continue;
            }
            $command = $lines[$i + 1] ?? '';
            if (($i > 0 && $lines[$i - 1] !== '') || ! str_starts_with($command, '$ ') || ($lines[$i + 2] ?? '') !== '--- stdout') {
                continue;
            }
            try {
                $started = CarbonImmutable::parse($match[1]);
            } catch (Throwable) {
                continue;
            }
            $command = substr($command, 2);
            if ($started->lessThan($since) || $started->greaterThan($now) || self::noOp($command)) {
                continue;
            }
            $records[] = ['start' => $match[1], 'label' => $match[2], 'node' => $match[3], 'command' => $command];
        }

        return new self($records);
    }

    /**
     * The declared nodes without a counted record, in declaration order.
     *
     * @param  list<string>  $nodes
     * @return list<string>
     */
    public function missing(array $nodes): array
    {
        $proven = array_flip(array_column($this->records, 'node'));

        return array_values(array_filter($nodes, static fn (string $node): bool => ! isset($proven[$node])));
    }

    /**
     * The counted records on the declared nodes, for the approval a person audits.
     *
     * @param  list<string>  $nodes
     */
    public function summary(array $nodes): string
    {
        $records = array_values(array_filter($this->records, static fn (array $record): bool => in_array($record['node'], $nodes, true)));
        $lines = array_map(
            static fn (array $record): string => '- '.$record['start'].' '.$record['node'].' "'.$record['label'].'": '.mb_substr($record['command'], 0, 300),
            array_slice($records, 0, self::SummaryLimit),
        );
        if (count($records) > self::SummaryLimit) {
            $lines[] = '- and '.(count($records) - self::SummaryLimit).' more in .e2e/evidence.log';
        }

        return "Topology evidence (exit 0, recorded by bin/e2e-topology):\n".implode("\n", $lines);
    }

    /**
     * The rubric reminder for nodes without evidence.
     *
     * @param  list<string>  $missing
     */
    public static function reminder(int $groupId, array $missing): string
    {
        return 'This subtask declares a topology, and the evidence log has no recorded command that did work and exited 0 since the last handoff on: '
            .implode(', ', $missing).'. Prove the deliverables there with `bin/e2e-topology exec TASK-'.$groupId.' NODE --argv=JSON --record=LABEL`, then approve again. Commands that only exit, such as `true`, do not count.';
    }

    /** Whether the shell-quoted argv only exits, also wrapped in `sh -c` or `bash -c`. */
    private static function noOp(string $command): bool
    {
        $words = self::words($command);
        $program = basename($words[0] ?? '');
        if (in_array($program, ['sh', 'bash'], true) && ($words[1] ?? null) === '-c') {
            $script = trim($words[2] ?? '');

            return $script === '' || self::noOp($script);
        }

        return $program === '' || in_array($program, self::NoOps, true);
    }

    /** @return list<string> */
    private static function words(string $command): array
    {
        preg_match_all("/(?:'(?:[^']|'\\\\'')*'|[^\\s'])+/", $command, $matches);

        return array_map(static fn (string $word): string => str_replace("'\\''", "'", preg_replace("/\\A'|'\\z/", '', $word) ?? $word), $matches[0]);
    }
}
