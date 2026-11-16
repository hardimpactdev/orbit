<?php

declare(strict_types=1);

use App\Domain\Proof\ProofVerdictLines;

it('reads one row from each verdict line of the report', function (): void {
    $report = implode("\n", [
        '{"id": "first", "verdict": "passed", "secs": 41.2}',
        '{"id": "protocols[both]", "verdict": "failed", "cause": "assertion", "secs": 3, "failed": ["expect[2] probe: app-prod to 47212/udp connected, expected blocked"]}',
        '{"id": "late", "verdict": "unrun", "cause": "infrastructure", "secs": 0, "failed": ["run stopped: a restore or the batch setup did not succeed"]}',
    ])."\n";

    expect(ProofVerdictLines::parse($report))->toBe([
        ['id' => 'first', 'verdict' => 'passed', 'seconds' => 41.2],
        ['id' => 'protocols[both]', 'verdict' => 'failed', 'seconds' => 3, 'cause' => 'assertion', 'failed' => ['expect[2] probe: app-prod to 47212/udp connected, expected blocked']],
        ['id' => 'late', 'verdict' => 'unrun', 'seconds' => 0, 'cause' => 'infrastructure', 'failed' => ['run stopped: a restore or the batch setup did not succeed']],
    ]);
});

it('keeps the exit code, error code and stderr tail of each failed CLI call of a case, and drops a call it cannot read', function (): void {
    $report = '{"id": "required-only", "verdict": "failed", "cause": "assertion", "secs": 0.4, "failed": ["act[0] cli node:converge: exit 1, expected 0"], '
        .'"calls": [{"run": "node:converge", "exit": 1, "code": "operation.target_busy", "stderr": "Another operation holds the Node."}, '
        .'{"run": "node:converge", "exit": 1, "code": null, "stderr": ""}, {"run": "node:converge", "exit": "1"}, {"exit": 1}, "bare"]}';

    expect(ProofVerdictLines::parse($report))->toBe([[
        'id' => 'required-only', 'verdict' => 'failed', 'seconds' => 0.4, 'cause' => 'assertion',
        'failed' => ['act[0] cli node:converge: exit 1, expected 0'],
        'calls' => [
            ['run' => 'node:converge', 'exit' => 1, 'code' => 'operation.target_busy', 'stderr' => 'Another operation holds the Node.'],
            ['run' => 'node:converge', 'exit' => 1, 'code' => null, 'stderr' => ''],
        ],
    ]]);
});

it('drops a line that is not a verdict, so a cut or noisy report never invents a pass', function (): void {
    $report = implode("\n", [
        'Traceback (most recent call last):',
        '{"id": "no-verdict", "secs": 1}',
        '{"id": "bad-verdict", "verdict": "ok", "secs": 1}',
        '{"id": "", "verdict": "passed", "secs": 1}',
        '{"id": "no-secs", "verdict": "passed"}',
        '{"id": "cut", "verdict": "passed", "se',
        '{"id": "kept", "verdict": "passed", "secs": 1}',
    ]);

    expect(ProofVerdictLines::parse($report))->toBe([['id' => 'kept', 'verdict' => 'passed', 'seconds' => 1]])
        ->and(ProofVerdictLines::parse(''))->toBe([]);
});

it('reads the lines of a new case that was proven on the merge base, named CASE@merge-base', function (): void {
    $report = implode("\n", [
        '{"id": "new-case", "verdict": "passed", "secs": 9}',
        '{"id": "new-case@merge-base", "verdict": "passed", "secs": 0}',
        '{"id": "other@merge-base", "verdict": "failed", "cause": "assertion", "secs": 0, "failed": ["the new case did not fail with cause assertion on the merge base"]}',
    ]);

    expect(array_column(ProofVerdictLines::parse($report), 'id'))->toBe(['new-case', 'new-case@merge-base', 'other@merge-base']);
});

it('keeps only measured nonnegative batch reset durations and never treats them as verdicts', function (): void {
    $report = implode("\n", [
        '{"batch":"fresh-fleet","reset":{"reset_seconds":70.2}}',
        '{"batch":"dual-stack","reset":{"reset_seconds":0,"error_code":"reset.failed"}}',
        '{"batch":"missing","reset":{}}',
        '{"batch":"negative","reset":{"reset_seconds":-1}}',
        '{"batch":"string","reset":{"reset_seconds":"70"}}',
        '{"batch":"infinite","reset":{"reset_seconds":1e999}}',
        '{"batch":"cut","reset":',
    ]);

    expect(ProofVerdictLines::batches($report))->toBe([
        ['batch' => 'fresh-fleet', 'reset_seconds' => 70.2],
        ['batch' => 'dual-stack', 'reset_seconds' => 0],
    ])->and(ProofVerdictLines::parse($report))->toBe([]);
});
