<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

$snapshot = sys_get_temp_dir().'/orbit-generated-merge-snapshot-'.bin2hex(random_bytes(8));

beforeAll(function () use ($snapshot): void {
    $process = new Process(['python3', '-S', dirname(__DIR__).'/generated_merge_test.py', '--prepare-fixture', $snapshot]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

afterAll(function () use ($snapshot): void {
    try {
        expect(file_exists($snapshot.'/.git/orbit/generated-merge'))->toBeFalse();
        expect(glob($snapshot.'/apps/*/bootstrap/cache/*.php'))->toBe([]);
        $process = new Process(['git', '-C', $snapshot, 'status', '--porcelain', '--untracked-files=all']);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect($process->getOutput())->toBe('');
    } finally {
        (new Filesystem)->deleteDirectory($snapshot);
    }
});

it('preserves the generated merge contract for :dataset', function (string $case) use ($snapshot): void {
    $process = new Process(['python3', '-S', dirname(__DIR__).'/generated_merge_test.py', '--fixture', $snapshot, 'GeneratedMergeTest.'.$case]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
})->with([
    'independent ADR additions' => 'test_independent_adr_additions',
    'reversed ADR additions' => 'test_reversed_adr_additions',
    'base OpenAPI freshness' => 'test_base_openapi_freshness',
    'base MCP freshness' => 'test_base_mcp_freshness',
    'base Docs lint freshness' => 'test_base_lint_freshness',
    'base navigation freshness' => 'test_base_navigation_freshness',
    'base web types freshness' => 'test_base_web_types_freshness',
    'runtime-only autoloaders' => 'test_runtime_only_autoloaders',
    'independent ADR previews' => 'test_independent_adr_previews',
    'reversed ADR previews' => 'test_reversed_adr_previews',
    'ADR OpenAPI freshness' => 'test_adr_openapi_freshness',
    'ADR MCP freshness' => 'test_adr_mcp_freshness',
    'ADR Docs lint freshness' => 'test_adr_lint_freshness',
    'ADR navigation freshness' => 'test_adr_navigation_freshness',
    'ADR web types freshness' => 'test_adr_web_types_freshness',
    'independent endpoint changes and cache reads' => 'test_independent_endpoint_changes',
    'reversed endpoint changes' => 'test_reversed_endpoint_changes',
    'independent endpoint previews' => 'test_independent_endpoint_previews',
    'reversed endpoint previews' => 'test_reversed_endpoint_previews',
    'endpoint OpenAPI freshness' => 'test_endpoint_openapi_freshness',
    'endpoint MCP freshness' => 'test_endpoint_mcp_freshness',
    'endpoint Docs lint freshness' => 'test_endpoint_lint_freshness',
    'endpoint navigation freshness' => 'test_endpoint_navigation_freshness',
    'endpoint web types freshness' => 'test_endpoint_web_types_freshness',
    'changed dependency locks' => 'test_changed_merged_locks_refuse_old_dependencies',
    'maintained conflicts' => 'test_maintained_conflicts_are_not_unioned',
    'source conflicts' => 'test_source_conflict_stays_unresolved',
    'caller-owned generator scheduling' => 'test_generators_run_in_caller_sequence',
]);
