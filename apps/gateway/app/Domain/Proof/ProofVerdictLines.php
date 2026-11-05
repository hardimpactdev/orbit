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
     * @return list<array{id:string,verdict:string,seconds:int|float,cause?:string,failed?:list<string>}> every line with a case id, a known verdict and a duration; any other line is dropped
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
            $rows[] = ['id' => $line['id'], 'verdict' => $line['verdict'], 'seconds' => $line['secs'],
                ...(is_string($line['cause'] ?? null) ? ['cause' => $line['cause']] : []), ...($failed !== [] ? ['failed' => $failed] : [])];
        }

        return $rows;
    }
}
