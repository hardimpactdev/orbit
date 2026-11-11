<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

/**
 * The public result of a run from Swamp's own run data, for kinds whose workflow has no result-reporting step
 * (flow-tighten rule 14). Steps are keyed by name; each keeps its status, its trimmed stdout and its exit code.
 * The summary has the counts and failed steps of Swamp's `@swamp/workflow-summary` report, taken from the same
 * history read, so the terminal projection still makes exactly one native read.
 */
final readonly class NativeRunResult
{
    public const int MaximumOutputSteps = 256;

    public const int MaximumStdoutBytes = 4096;

    /** The status of one named step in a native result, or null when the step is absent.
     * @param  array<string,mixed>  $result
     */
    public static function stepStatus(array $result, string $step): ?string
    {
        $steps = is_array($result['steps'] ?? null) ? $result['steps'] : [];
        $entry = is_array($steps[$step] ?? null) ? $steps[$step] : [];

        return is_string($entry['status'] ?? null) ? $entry['status'] : null;
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array{steps:array<string,array{status:string,stdout?:string,exit_code?:int,error_code?:string}>,summary:array{status:string,succeeded:int,failed:int,skipped:int,failed_steps:list<string>}}
     */
    public function fromRun(array $run): array
    {
        $steps = [];
        $counts = ['succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        $failed = [];
        foreach (is_array($run['jobs'] ?? null) ? $run['jobs'] : [] as $job) {
            foreach (is_array($job) && is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (! is_array($step) || ! is_string($step['name'] ?? null) || ! is_string($step['status'] ?? null)) {
                    continue;
                }
                $entry = ['status' => $step['status']];
                if (is_string($step['error'] ?? null) && strlen($step['error']) <= 128
                    && preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $step['error']) === 1) {
                    $entry['error_code'] = $step['error'];
                }
                $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : [];
                if (count($steps) < self::MaximumOutputSteps && is_string($outputs['stdout'] ?? null)) {
                    $entry['stdout'] = substr($outputs['stdout'], 0, self::MaximumStdoutBytes);
                }
                if (count($steps) < self::MaximumOutputSteps && is_int($outputs['exitCode'] ?? null)) {
                    $entry['exit_code'] = $outputs['exitCode'];
                }
                $steps[$step['name']] = $entry;
                if (array_key_exists($step['status'], $counts)) {
                    $counts[$step['status']]++;
                }
                if ($step['status'] === 'failed' && ($step['allowedFailure'] ?? false) !== true) {
                    $failed[] = $step['name'];
                }
            }
        }

        return ['steps' => $steps, 'summary' => ['status' => is_string($run['status'] ?? null) ? $run['status'] : 'unknown', ...$counts, 'failed_steps' => $failed]];
    }
}
