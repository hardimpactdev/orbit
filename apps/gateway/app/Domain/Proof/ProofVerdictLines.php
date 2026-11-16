<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/**
 * The verdict lines of one proof run: a JSON object a line, at most 1 KB each, from `runner case` or from the report step
 * of the workflow. See docs/reference/verification-cases#the-run.
 */
final class ProofVerdictLines
{
    private const array Verdicts = ['passed', 'failed', 'unrun'];

    /**
     * A failed case also keeps its failed CLI calls (`calls`): the command, exit code, error code and stderr tail of each call that did not
     * do what the case expects. A call without a command name and an integer exit code is dropped.
     *
     * @return list<array{id:string,verdict:string,seconds:int|float,cause?:string,failed?:list<string>,calls?:list<array{run:string,exit:int,code:string|null,stderr:string}>}> every line with a case id, a known verdict and a duration; any other line is dropped
     */
    public static function parse(string $report): array
    {
        $rows = [];
        foreach (explode("\n", $report) as $text) {
            $line = json_decode(trim($text), true);
            if (! is_array($line) || ! is_string($line['id'] ?? null) || $line['id'] === '' || ! in_array($line['verdict'] ?? null, self::Verdicts, true)
                || ! (is_int($line['secs'] ?? null) || is_float($line['secs'] ?? null))) {
                continue;
            }
            $failed = is_array($line['failed'] ?? null) ? array_values(array_filter($line['failed'], is_string(...))) : [];
            $calls = self::calls($line['calls'] ?? null);
            $rows[] = ['id' => $line['id'], 'verdict' => $line['verdict'], 'seconds' => $line['secs'],
                ...(is_string($line['cause'] ?? null) ? ['cause' => $line['cause']] : []), ...($failed !== [] ? ['failed' => $failed] : []),
                ...($calls !== [] ? ['calls' => $calls] : [])];
        }

        return $rows;
    }

    /** @return list<array{run:string,exit:int,code:string|null,stderr:string}> */
    private static function calls(mixed $calls): array
    {
        $kept = [];
        foreach (is_array($calls) ? $calls : [] as $call) {
            if (is_array($call) && is_string($call['run'] ?? null) && $call['run'] !== '' && is_int($call['exit'] ?? null)) {
                $kept[] = ['run' => $call['run'], 'exit' => $call['exit'], 'code' => is_string($call['code'] ?? null) ? $call['code'] : null,
                    'stderr' => is_string($call['stderr'] ?? null) ? $call['stderr'] : ''];
            }
        }

        return $kept;
    }

    /** @return list<array{batch:string,reset_seconds:int|float}> Reset timings are evidence only, separate from case verdicts. */
    public static function batches(string $report): array
    {
        $rows = [];
        foreach (explode("\n", $report) as $text) {
            $line = json_decode(trim($text), true);
            if (! is_array($line) || ! is_string($line['batch'] ?? null) || $line['batch'] === '' || ! is_array($line['reset'] ?? null)) {
                continue;
            }
            $seconds = $line['reset']['reset_seconds'] ?? null;
            if ((is_int($seconds) || is_float($seconds)) && is_finite((float) $seconds) && $seconds >= 0) {
                $rows[] = ['batch' => $line['batch'], 'reset_seconds' => $seconds];
            }
        }

        return $rows;
    }
}
