<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\Value\EvidenceLabel;
use App\E2E\Value\GuestCommandResult;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * The append-only evidence of one worktree, in two files under `<worktree>/.e2e/`.
 *
 * `exec --record` and `logs --record` append one redacted entry per command to each, under one lock:
 * - `evidence.log`, for people: a `===` header with the start time, label, Node, exit code, duration, and end
 *   time, the argv as shell words, then the stdout and stderr sections.
 * - `evidence.jsonl`, for the Gateway: one JSON object per line with `v`, `start`, `end`, `duration_ms`,
 *   `label`, `node`, `exit`, and `argv` as a list, without the output.
 *
 * The Gateway's evidence gate is a cooperative check that the reviewer audits: the agent owns the VM and can
 * write either file itself.
 */
final readonly class EvidenceLog
{
    public const string FILE = 'evidence.log';

    public const string RECORDS = 'evidence.jsonl';

    public const int RECORD_VERSION = 1;

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public function __construct(
        private SecretRedactor $redactor,
    ) {}

    /** @param list<string> $argv */
    public function append(
        string $worktree,
        EvidenceLabel $label,
        string $node,
        array $argv,
        GuestCommandResult $result,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
    ): void {
        $utc = new DateTimeZone('UTC');
        $start = $startedAt->setTimezone($utc);
        $end = $endedAt->setTimezone($utc);
        $duration = max(0, intdiv((int) $end->format('Uu') - (int) $start->format('Uu'), 1000));
        $argv = $this->redactor->redactArgv($argv);
        $record = json_encode([
            'v' => self::RECORD_VERSION,
            'start' => $start->format(self::TIME_FORMAT),
            'end' => $end->format(self::TIME_FORMAT),
            'duration_ms' => $duration,
            'label' => $label->value,
            'node' => $node,
            'exit' => $result->exitCode,
            'argv' => $argv,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        $paths = StatePaths::forWorktree($worktree);
        $log = $this->open($paths->ensureParent(self::FILE));
        try {
            $records = $this->open($paths->ensureParent(self::RECORDS));
            try {
                // The lock on the text log covers both appends, so the two files keep one order.
                if (! flock($log, LOCK_EX)) {
                    throw new RuntimeException('Unable to lock the evidence log.');
                }
                $this->write($log, $this->entry($label, $node, $argv, $result, $start, $end, $duration));
                $this->write($records, $record);
            } finally {
                fclose($records);
            }
        } finally {
            flock($log, LOCK_UN);
            fclose($log);
        }
    }

    /** @return resource */
    private function open(string $path)
    {
        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, 'ab');
        } finally {
            umask($previousUmask);
        }

        return $handle === false ? throw new RuntimeException('Unable to open the evidence log.') : $handle;
    }

    /** @param resource $handle */
    private function write($handle, string $content): void
    {
        if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
            throw new RuntimeException('Unable to append to the evidence log.');
        }
    }

    /** @param list<string> $argv redacted */
    private function entry(
        EvidenceLabel $label,
        string $node,
        array $argv,
        GuestCommandResult $result,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $duration,
    ): string {
        $words = array_map($this->shellWord(...), $argv);

        return '=== '.$start->format(self::TIME_FORMAT)." {$label->value} node={$node}"
            ." exit={$result->exitCode} duration={$duration}ms end=".$end->format(self::TIME_FORMAT)."\n"
            .'$ '.implode(' ', $words)."\n"
            ."--- stdout\n"
            .$this->section($result->stdout)
            ."--- stderr\n"
            .$this->section($result->stderr)
            ."\n";
    }

    private function section(string $output): string
    {
        $redacted = $this->redactor->redact($output);

        return $redacted === '' || str_ends_with($redacted, "\n") ? $redacted : $redacted."\n";
    }

    private function shellWord(string $word): string
    {
        if ($word !== '' && preg_match('/\A[A-Za-z0-9_@%+=:,.\/-]+\z/', $word) === 1) {
            return $word;
        }

        return "'".str_replace("'", "'\\''", $word)."'";
    }
}
