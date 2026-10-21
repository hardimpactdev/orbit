<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('preserves the generated merge contract for :dataset', function (string $case): void {
    $process = new Process(['python3', dirname(__DIR__).'/generated_merge_test.py', 'GeneratedMergeTest.'.$case]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
})->with([
    'independent ADR additions' => 'test_independent_adr_additions',
    'reversed ADR additions' => 'test_reversed_adr_additions',
    'base freshness' => 'test_base_freshness',
    'independent ADR previews' => 'test_independent_adr_previews',
    'reversed ADR previews' => 'test_reversed_adr_previews',
    'ADR freshness' => 'test_adr_freshness',
    'independent endpoint changes and cache reads' => 'test_independent_endpoint_changes',
    'reversed endpoint changes' => 'test_reversed_endpoint_changes',
    'independent endpoint previews' => 'test_independent_endpoint_previews',
    'reversed endpoint previews' => 'test_reversed_endpoint_previews',
    'endpoint freshness' => 'test_endpoint_freshness',
    'changed dependency locks' => 'test_changed_merged_locks_refuse_old_dependencies',
    'maintained conflicts' => 'test_maintained_conflicts_are_not_unioned',
    'source conflicts' => 'test_source_conflict_stays_unresolved',
]);
