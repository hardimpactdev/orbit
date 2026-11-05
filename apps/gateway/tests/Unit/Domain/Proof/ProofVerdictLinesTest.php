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
