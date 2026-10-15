<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\EvidenceLog;
use App\E2E\Value\EvidenceLabel;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\TopologyRequest;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Appends one `--record=LABEL` entry to the worktree's evidence files, shared by `exec` and `logs`. */
trait RecordsEvidence
{
    /** The option signature both commands declare. */
    protected const string RECORD_OPTION = ' {--record= : Append the command, its timestamps, and its output to .e2e/evidence.log, and one JSON record to .e2e/evidence.jsonl, under this label}';

    /** The validated label, or null without `--record`; checked before any infrastructure access. */
    protected function evidenceLabel(): ?EvidenceLabel
    {
        $value = $this->stringOption('record');

        return $value === null ? null : new EvidenceLabel($value);
    }

    protected function evidenceClock(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Recording never changes the command's exit code or output; a failed append
     * only warns on stderr.
     *
     * @param  list<string>  $argv
     */
    protected function recordEvidence(
        ?EvidenceLabel $label,
        TopologyRequest $request,
        string $role,
        array $argv,
        GuestCommandResult $result,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
    ): void {
        if (! $label instanceof EvidenceLabel) {
            return;
        }
        try {
            app(EvidenceLog::class)->append(
                $request->worktree,
                $label,
                $role,
                $argv,
                $result,
                $startedAt,
                $endedAt,
            );
        } catch (Throwable $exception) {
            $this->output->getErrorStyle()->writeln(
                'warning: the evidence entry was not recorded: '.$exception->getMessage(),
            );
        }
    }
}
