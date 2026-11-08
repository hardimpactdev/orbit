<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** Merge the assigned batches and account for every expected case, including a truncated or missing report. */
final class ProofShardVerdicts
{
    /**
     * @param  list<array{command:string,file:string,batch:string,cases:list<string>,seconds?:float}>  $assignments
     * @return array{ran:bool,cases:list<array<string,mixed>>}
     */
    public static function rows(array $assignments, string $report): array
    {
        $reports = [];
        $invalid = false;
        foreach (explode("\n", trim($report)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 4);
            if (count($parts) !== 4) {
                $invalid = true;

                continue;
            }
            $key = implode("\t", array_slice($parts, 0, 3));
            $reports[$key][] = $parts[3];
        }
        $cases = [];
        $ran = false;
        foreach ($assignments as $assignment) {
            $command = $assignment['command'];
            $file = $assignment['file'];
            $key = $command."\t".$file."\t".$assignment['batch'];
            $lines = $reports[$key] ?? [];
            unset($reports[$key]);
            $expected = $assignment['cases'];
            $seen = [];
            foreach ($lines as $line) {
                $parsed = ProofVerdictLines::parse($line);
                if (count($parsed) !== 1) {
                    $invalid = true;

                    continue;
                }
                $row = $parsed[0];
                $id = $row['id'];
                if (isset($seen[$id]) || ($expected !== [] && ! in_array($id, $expected, true)
                    && ! (str_ends_with($id, '@merge-base') && in_array(substr($id, 0, -11), $expected, true)))) {
                    $invalid = true;
                }
                $seen[$id] = true;
                $ran = $ran || $row['verdict'] !== 'unrun';
                $cases[] = ['program' => $file.'#'.$id, 'command' => $command, 'case' => $id, ...array_diff_key($row, ['id' => 0])];
            }
            foreach ($expected as $id) {
                if (! isset($seen[$id])) {
                    $cases[] = self::missing($command, $file, $id);
                }
            }
            if ($seen === [] && $expected === []) {
                $cases[] = self::missing($command, $file, '');
            }
        }
        if ($invalid || $reports !== []) {
            $cases[] = ['program' => 'proof-report', 'command' => '', 'verdict' => 'unrun', 'seconds' => 0, 'cause' => 'infrastructure',
                'failed' => ['The report contains malformed, duplicate or unassigned verdicts.']];
        }

        return ['ran' => $ran, 'cases' => $cases];
    }

    /** @return array<string,mixed> */
    private static function missing(string $command, string $file, string $id): array
    {
        return ['program' => $file.($id === '' ? '' : '#'.$id), 'command' => $command, ...($id === '' ? [] : ['case' => $id]),
            'verdict' => 'unrun', 'seconds' => 0, 'cause' => 'infrastructure', 'failed' => ['The selected case has no verdict.']];
    }
}
