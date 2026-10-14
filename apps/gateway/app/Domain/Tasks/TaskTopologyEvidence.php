<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JsonException;
use Throwable;

/**
 * The records of a topology's evidence that count for an approval. `bin/e2e-topology exec --record` and
 * `logs --record` append one JSON line per command to `.e2e/evidence.jsonl`:
 * `{"v":1,"start":…,"end":…,"label":…,"node":…,"exit":…,"argv":[…]}`, with UTC times in milliseconds and the redacted
 * argv as a list ({@see self::fromJsonLines()}). A harness from before that file wrote only the text log
 * `.e2e/evidence.log`, which {@see self::parse()} still reads until those VMs are gone.
 *
 * A record counts when its command exited 0, started at or after the last handoff and not after now, and did work on
 * its node: a command that only exits, such as `true` or `:`, proves nothing.
 *
 * This is a cooperative check, not an anti-forgery control. The agent owns the VM and can write the file directly, so
 * the gate catches a reviewer that skipped the nodes; the approval carries the counted records for a person to audit.
 */
final readonly class TaskTopologyEvidence
{
    public const string File = '.e2e/evidence.jsonl';

    public const string LegacyFile = '.e2e/evidence.log';

    private const string Header = '/\A=== (\S+) (.+) node=([a-z0-9-]+) exit=(-?\d+) duration=\d+ms end=\S+\z/D';

    private const string Node = '/\A[a-z0-9-]+\z/D';

    /** Programs that do no work on the node. */
    private const array NoOps = ['true', ':', 'false', 'exit', 'echo', 'printf', 'sleep', 'yes'];

    private const int SummaryLimit = 20;

    /** @param list<array{start: string, label: string, node: string, command: string}> $records */
    private function __construct(public array $records, private string $file = self::File) {}

    public static function none(): self
    {
        return new self([]);
    }

    /** The records of `.e2e/evidence.jsonl`. A line that is not a version 1 record, such as a cut first line, is skipped. */
    public static function fromJsonLines(string $lines, CarbonInterface $since, CarbonInterface $now): self
    {
        $records = [];
        foreach (explode("\n", $lines) as $line) {
            try {
                $record = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($record) || ($record['v'] ?? null) !== 1 || ($record['exit'] ?? null) !== 0
                || ! is_string($record['start'] ?? null) || ! is_string($record['label'] ?? null)
                || ! is_string($record['node'] ?? null) || preg_match(self::Node, $record['node']) !== 1
                || ! is_array($record['argv'] ?? null) || ! array_is_list($record['argv'])) {
                continue;
            }
            $argv = array_values(array_filter($record['argv'], is_string(...)));
            if (count($argv) !== count($record['argv'])) {
                continue;
            }
            if (self::counts($record['start'], $argv, $since, $now)) {
                $records[] = ['start' => $record['start'], 'label' => $record['label'], 'node' => $record['node'], 'command' => implode(' ', array_map(self::shellWord(...), $argv))];
            }
        }

        return new self($records);
    }

    /**
     * The records of the text log `.e2e/evidence.log` that a harness from before `.e2e/evidence.jsonl` wrote: a blank
     * line or the start of the file, then `=== <start> <label> node=<node> exit=<code> duration=<ms>ms end=<end>`, the
     * argv as `$ <shell words>`, and `--- stdout`.
     */
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
            $command = substr($command, 2);
            if (self::counts($match[1], self::words($command), $since, $now)) {
                $records[] = ['start' => $match[1], 'label' => $match[2], 'node' => $match[3], 'command' => $command];
            }
        }

        return new self($records, self::LegacyFile);
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
            $lines[] = '- and '.(count($records) - self::SummaryLimit).' more in '.$this->file;
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

    /** @param list<string> $argv */
    private static function counts(string $start, array $argv, CarbonInterface $since, CarbonInterface $now): bool
    {
        try {
            $started = CarbonImmutable::parse($start);
        } catch (Throwable) {
            return false;
        }

        return ! $started->lessThan($since) && ! $started->greaterThan($now) && ! self::noOp($argv);
    }

    /**
     * Whether the argv only exits, also wrapped in `sh -c` or `bash -c`.
     *
     * @param  list<string>  $argv
     */
    private static function noOp(array $argv): bool
    {
        $program = basename($argv[0] ?? '');
        if (in_array($program, ['sh', 'bash'], true) && ($argv[1] ?? null) === '-c') {
            $script = trim($argv[2] ?? '');

            return $script === '' || self::noOp(self::words($script));
        }

        return $program === '' || in_array($program, self::NoOps, true);
    }

    /**
     * The words of shell text, with single quotes removed.
     *
     * @return list<string>
     */
    private static function words(string $command): array
    {
        preg_match_all("/(?:'(?:[^']|'\\\\'')*'|[^\\s'])+/", $command, $matches);

        return array_map(static fn (string $word): string => str_replace("'\\''", "'", preg_replace("/\\A'|'\\z/", '', $word) ?? $word), $matches[0]);
    }

    private static function shellWord(string $word): string
    {
        return $word !== '' && preg_match('/\A[A-Za-z0-9_@%+=:,.\/-]+\z/', $word) === 1 ? $word : "'".str_replace("'", "'\\''", $word)."'";
    }
}
