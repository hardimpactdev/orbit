<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** The rows of one command's cases, from the report step of its run. */
final class ProofVerdicts
{
    /**
     * A command whose report holds no line, because the VM or the fleet failed before its cases, is one unrun row.
     *
     * @return array{ran: bool, cases: list<array<string,mixed>>} `ran` is true when at least one case got a verdict other than unrun
     */
    public static function rows(string $command, string $file, string $report): array
    {
        $ran = false;
        $cases = [];
        foreach (ProofVerdictLines::parse($report) as $line) {
            $ran = $ran || $line['verdict'] !== 'unrun';
            $cases[] = ['program' => $file.'#'.$line['id'], 'command' => $command, 'case' => $line['id'], ...array_diff_key($line, ['id' => 0])];
        }

        return ['ran' => $ran, 'cases' => $cases !== [] ? $cases : [['program' => $file, 'command' => $command, 'verdict' => 'unrun', 'seconds' => 0]]];
    }

    /** @param list<array<string,mixed>> $cases */
    public static function passed(array $cases): bool
    {
        return $cases !== [] && array_all($cases, static fn (array $case): bool => $case['verdict'] === 'passed');
    }
}
