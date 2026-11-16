<?php

declare(strict_types=1);

use App\Domain\Proof\ProofBatchPlan;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofShardVerdicts;
use App\Domain\Proof\ProofVerdicts;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('balances whole batches using the latest measured case times and expands variant identities', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'host', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit']));
    $file = 'swamp/proof/firewall/allow.cases.yaml';
    Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'succeeded', 'input' => [], 'result' => ['cases' => [
            ['program' => $file.'#a', 'seconds' => 20], ['program' => $file.'#b[v4]', 'seconds' => 7], ['program' => $file.'#b[v6]', 'seconds' => 6],
            ['program' => $file.'#c', 'seconds' => 100],
        ]]]);
    Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'failed', 'input' => [], 'result' => ['cases' => [['program' => $file.'#c', 'seconds' => 5]]]]);
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches:\n  first: {}\n  second: {}\n  third: {}\n  fourth: {}\n");
    $source->shouldReceive('file')->with('head', $file)->andReturn("command: firewall:allow\ncases:\n  - {id: a, batch: first}\n  - {id: b, batch: second, variants: [{name: v4}, {name: v6}]}\n  - {id: c, batch: third}\n  - {id: d, batch: fourth}\n");

    $batches = (new ProofBatchPlan($source))->read('head', [['command' => 'firewall:allow', 'file' => $file]]);
    $shards = ProofBatchPlan::split($batches, 2);

    expect(array_column($batches, 'seconds'))->toBe([20.0, 13.0, 5.0, 6.5])
        ->and($batches[1]['cases'])->toBe(['b[v4]', 'b[v6]'])
        // Each batch weighs its reset and setup beside its cases, so the four batches go two to a copy.
        ->and(array_column($shards[0], 'batch'))->toBe(['first', 'third'])
        ->and(array_column($shards[1], 'batch'))->toBe(['second', 'fourth']);
});

it('weighs the reset of each batch, so short batches do not pile up on one copy', function (): void {
    $batch = static fn (string $name, float $seconds): array => ['command' => 'node:converge', 'file' => 'converge.cases.yaml', 'batch' => $name,
        'cases' => ['a'], 'seconds' => $seconds, 'read_only' => false];
    $shards = ProofBatchPlan::split([$batch('long-1', 100.0), $batch('long-2', 100.0), $batch('a', 1.0), $batch('b', 1.0), $batch('c', 1.0), $batch('d', 1.0)], 3);

    // With case seconds alone the copy of the first short batch stays least loaded and takes all four short batches, with four resets.
    expect(array_map('count', $shards))->toBe([2, 2, 2]);
});

it('runs the read-only cases of a batch without setup as a batch of their own, first on its copy and without a reset', function (): void {
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches:\n  fresh: {}\n  no-grant:\n    setup:\n      - node_access_remove: {consumer: app-prod, serving: app-dev}\n");
    $source->shouldReceive('file')->with('head', 'converge.cases.yaml')->andReturn("command: node:converge\ncases:\n"
        ."  - {id: apply, batch: fresh}\n  - {id: unknown, batch: fresh, read_only: true, variants: [{name: one}, {name: two}]}\n"
        ."  - {id: refused, batch: no-grant, read_only: true}\n");

    $batches = (new ProofBatchPlan($source))->read('head', [['command' => 'node:converge', 'file' => 'converge.cases.yaml']]);
    $shards = ProofBatchPlan::split($batches, 1);

    expect(array_map(static fn (array $batch): array => [$batch['batch'], $batch['cases'], $batch['read_only']], $batches))->toBe([
        ['fresh', ['apply'], false], ['fresh', ['unknown[one]', 'unknown[two]'], true],
        // A batch with setup changes the fleet before its cases, so its read-only cases stay in it.
        ['no-grant', ['refused'], false],
    ])
        ->and(array_column($shards[0], 'read_only'))->toBe([true, false, false])
        // The read-only batch needs no reset: two resets with setup and the case seconds.
        ->and(ProofBatchPlan::work($shards[0]))->toBe(2 * ProofBatchPlan::BatchSeconds + 4.0)
        ->and(ProofBatchPlan::work([$batches[1]]))->toBe(ProofBatchPlan::BatchSeconds + 2.0);
});

it('merges the verdicts of a read-only batch and the rest of its batch on one copy', function (): void {
    $assignments = [['command' => 'node:show', 'file' => 'show.cases.yaml', 'batch' => 'fresh', 'cases' => ['refused'], 'read_only' => true],
        ['command' => 'node:show', 'file' => 'show.cases.yaml', 'batch' => 'fresh', 'cases' => ['read'], 'read_only' => false]];
    $row = "node:show\tshow.cases.yaml\tfresh\t";
    $rows = ProofShardVerdicts::rows($assignments, $row.'{"id":"refused","verdict":"passed","secs":1}'."\n".$row.'{"id":"read","verdict":"passed","secs":1}');

    expect(ProofVerdicts::passed($rows['cases']))->toBeTrue()->and(array_column($rows['cases'], 'case'))->toBe(['refused', 'read']);
});

it('refuses a selected command with no cases instead of planning a green zero-case run', function (?string $yaml): void {
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches:\n  fresh: {}\n");
    $source->shouldReceive('file')->with('head', 'cases.yaml')->andReturn($yaml);
    expect(fn () => (new ProofBatchPlan($source))->read('head', [['command' => 'firewall:allow', 'file' => 'cases.yaml']]))
        ->toThrow(ResourceOperationException::class);
})->with([null, 'command: firewall:allow', 'command: firewall:allow'."\ncases: []\n", "command: firewall:allow\ncases:\n  - {id: a, batch: absent}\n"]);

it('accounts for every assigned case and fails missing duplicate malformed and unassigned verdicts', function (string $report, bool $passed): void {
    $assignments = [['command' => 'firewall:allow', 'file' => 'cases.yaml', 'batch' => 'fresh', 'cases' => ['a', 'b']]];
    $prefix = "firewall:allow\tcases.yaml\tfresh\t";
    $report = str_replace('ROW', $prefix, $report);
    $rows = ProofShardVerdicts::rows($assignments, $report);
    expect(ProofVerdicts::passed($rows['cases']))->toBe($passed);
    if (! $passed) {
        expect(array_column($rows['cases'], 'verdict'))->toContain('unrun');
    }
})->with([
    'complete' => ["ROW{\"id\":\"a\",\"verdict\":\"passed\",\"secs\":1}\nROW{\"id\":\"b\",\"verdict\":\"passed\",\"secs\":1}", true],
    'partial' => ['ROW{"id":"a","verdict":"passed","secs":1}', false],
    'empty' => ['', false],
    'duplicate' => ["ROW{\"id\":\"a\",\"verdict\":\"passed\",\"secs\":1}\nROW{\"id\":\"a\",\"verdict\":\"passed\",\"secs\":1}", false],
    'malformed' => ['ROW{"id":"a","verdict":"passed"}', false],
    'unexpected' => ['ROW{"id":"extra","verdict":"passed","secs":1}', false],
    // Proof 22900 (release 2084): a copy's batch adds its reset seconds; every case passed, and the run must pass.
    'complete with the reset line of a copy' => ["ROW{\"id\":\"a\",\"verdict\":\"passed\",\"secs\":1}\nROW{\"id\":\"b\",\"verdict\":\"passed\",\"secs\":1}\nROW{\"batch\": \"fresh\", \"reset\": {\"reset_seconds\": 16.2}}", true],
    'a reset line alone' => ['ROW{"batch": "fresh", "reset": {"reset_seconds": 16.2}}', false],
    'a verdict that also carries reset fields still counts' => ["ROW{\"batch\":\"fresh\",\"reset\":{\"reset_seconds\":1},\"id\":\"a\",\"verdict\":\"failed\",\"secs\":1}\nROW{\"id\":\"a\",\"verdict\":\"passed\",\"secs\":1}\nROW{\"id\":\"b\",\"verdict\":\"passed\",\"secs\":1}", false],
]);

// Proof 25268 (release 2120): five node:converge calls exited 1 before admission, and the result kept no cause. A failed case keeps its calls.
it('keeps the failed CLI calls of a case in its result row', function (): void {
    $assignments = [['command' => 'node:converge', 'file' => 'cases.yaml', 'batch' => 'fresh', 'cases' => ['required-only']]];
    $report = "node:converge\tcases.yaml\tfresh\t".'{"id": "required-only", "verdict": "failed", "cause": "assertion", "secs": 0.4, '
        .'"failed": ["act[0] cli node:converge: exit 1, expected 0"], "calls": [{"run": "node:converge", "exit": 1, "code": "operation.target_busy", "stderr": "busy"}]}';

    $rows = ProofShardVerdicts::rows($assignments, $report);

    expect($rows['cases'])->toHaveCount(1)
        ->and($rows['cases'][0]['calls'])->toBe([['run' => 'node:converge', 'exit' => 1, 'code' => 'operation.target_busy', 'stderr' => 'busy']]);
});

// The real Report output of the two copy runs of proof 22900 on release 2084 (Operations 22902 and 22904): verdict lines and one reset
// line per batch, nothing else. Every case passed, so each shard passes, with no report row of its own.
it('passes the real reports of two copy runs: one reset line per batch, every case passed', function (string $file, array $assignments): void {
    $rows = ProofShardVerdicts::rows($assignments, (string) file_get_contents(__DIR__.'/../../../Fixtures/Proof/'.$file));

    expect(ProofVerdicts::passed($rows['cases']))->toBeTrue()->and($rows['ran'])->toBeTrue()
        ->and(array_column($rows['cases'], 'program'))->not->toContain('proof-report')
        ->and(array_column($rows['cases'], 'case'))->toBe(array_merge(...array_column($assignments, 'cases')));
})->with([
    'run 22902' => ['copy-report-22902.txt', [['command' => 'node:list', 'file' => 'swamp/proof/node/list.cases.yaml', 'batch' => 'node-down', 'cases' => ['offline-node-retained']]]],
    'run 22904' => ['copy-report-22904.txt', [
        ['command' => 'node:list', 'file' => 'swamp/proof/node/list.cases.yaml', 'batch' => 'fresh-fleet', 'cases' => ['recorded-nodes', 'repeat-read', 'role-state-human']],
        ['command' => 'node:list', 'file' => 'swamp/proof/node/list.cases.yaml', 'batch' => 'no-grant', 'cases' => ['no-grants-refused']]]],
]);

it('plans selected ids only, retains their batch setup and rejects an unknown id', function (): void {
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches: {fresh: {setup: [{ipv6_addresses: {nodes: [gateway]}}]}, unused: {}}\n");
    $source->shouldReceive('file')->with('head', 'cases.yaml')->andReturn("command: demo:run\ncases: [{id: a, batch: fresh}, {id: b, batch: fresh}, {id: c, batch: unused}]\n");
    $plan = new ProofBatchPlan($source);
    $selected = ['command' => 'demo:run', 'file' => 'cases.yaml', 'only' => ['b']];
    expect($plan->read('head', [$selected]))->toHaveCount(1)
        ->and($plan->read('head', [$selected])[0])->toMatchArray(['batch' => 'fresh', 'cases' => ['b']]);
    expect(fn () => $plan->read('head', [[...$selected, 'only' => ['missing']]]))->toThrow(ResourceOperationException::class);
});

it('gives a shard the budget of its batches and keeps more batches than one budget fits out of one shard', function (): void {
    // Proof 14651: 17 batches of about 75 measured seconds each, about 81 cases.
    $batches = array_map(static fn (int $index): array => ['command' => 'node:add', 'file' => 'cases.yaml', 'batch' => 'b'.$index, 'cases' => ['c'.$index], 'seconds' => 75.0], range(1, 17));
    $one = ProofBatchPlan::split($batches, 1);
    $two = ProofBatchPlan::split($batches, 2);

    // (600 + 3 x 180 + 3 x 100) x 1.5 = 2,160, rounded up to five minutes.
    expect(ProofBatchPlan::budget(array_fill(0, 3, ['seconds' => 100.0])))->toBe(2400)
        // On one place the shard needs (600 + 17 x 255) x 1.5 = 7,402 s: more than one shard may take.
        ->and(ProofBatchPlan::overBudget($one[0]))->toBeTrue()
        ->and(ProofBatchPlan::budget($one[0]))->toBe(ProofBatchPlan::MaxShardSeconds)
        ->and(array_map(ProofBatchPlan::overBudget(...), $two))->toBe([false, false])
        ->and(array_map(ProofBatchPlan::budget(...), $two))->toBe([4500, 4200])
        // One batch cannot be split: it runs with the longest budget.
        ->and(ProofBatchPlan::overBudget([['seconds' => 9000.0]]))->toBeFalse()
        ->and(ProofBatchPlan::budget([['seconds' => 9000.0]]))->toBe(ProofBatchPlan::MaxShardSeconds);
});

/**
 * The worst case of the node proof: all 7 node commands on 10 copies, from case times measured on a nested fleet (proof 25268 and
 * single cases run in a copy). Estimates where no case time was measured are marked. The batch seconds are the cases in series.
 */
it('plans the worst node selection at its floor, balances the other copies and names the batches that keep it over five minutes', function (): void {
    $batches = [];
    foreach ([
        'node:converge' => ['fresh-fleet' => [506.0, 11.0], 'no-grant' => [220.0, null], 'node-down' => [10.0 /* estimate */, null]],
        'node:role:add' => ['fresh-fleet' => [317.0, 14.0], 'ufw-fault' => [96.0, null], 'node-down' => [30.0 /* estimate */, null], 'no-grant' => [60.0 /* estimate */, null]],
        'node:metrics' => ['fresh-fleet' => [80.0, 7.0], 'node-down' => [120.0, null], 'no-grant' => [23.0, null]],
        'node:show' => ['fresh-fleet' => [70.0, 10.0], 'node-down' => [14.0, null], 'no-grant' => [14.0, null]],
        'node:list' => ['fresh-fleet' => [null, 30.0], 'node-down' => [43.0, null], 'no-grant' => [20.0, null]],
        'node:role:list' => ['fresh-fleet' => [null, 8.0], 'node-down' => [6.0, null], 'no-grant' => [15.0, null]],
        'node:update' => ['fresh-fleet' => [3.0, 1.0]],
    ] as $command => $named) {
        foreach ($named as $name => $parts) {
            foreach ($parts as $index => $seconds) {
                if ($seconds !== null) {
                    $batches[] = ['command' => $command, 'file' => $command.'.cases.yaml', 'batch' => $name, 'cases' => [], 'seconds' => $seconds, 'read_only' => $index === 1];
                }
            }
        }
    }
    // Measured on the outer and the nested fleet: admission, the copy creates, the commit, one reset of the inner fleet, the longest setup.
    $before = 57 + 75 + 9;
    $reset = 60 + 36;
    $limit = 300;
    $copyEnds = static fn (array $shard): float => $before + max(1, count(array_filter($shard, static fn (array $batch): bool => ! $batch['read_only']))) * $reset
        + array_sum(array_column($shard, 'seconds'));
    $shards = ProofBatchPlan::split($batches, 10);
    $ends = array_map($copyEnds, $shards);
    // A batch never splits, so no plan ends before the longest batch on a copy of its own.
    $floor = max(array_map(static fn (array $batch): float => $copyEnds([$batch]), $batches));

    expect(count($shards))->toBe(10)
        ->and(max($ends))->toBe($floor)
        // The other copies end within one reset and setup of each other: the reset weight keeps short batches from piling up.
        ->and(max(array_diff_key($ends, [array_search($floor, $ends, true) => 0])) - min($ends))->toBeLessThanOrEqual(ProofBatchPlan::BatchSeconds)
        // Five minutes needs these batches shorter: their cases alone are longer than what is left after admission, copy, reset and setup.
        ->and(array_values(array_map(static fn (array $batch): string => $batch['command'].' '.$batch['batch'], array_filter($batches, static fn (array $batch): bool => $copyEnds([$batch]) > $limit))))
        ->toBe(['node:converge fresh-fleet', 'node:converge no-grant', 'node:role:add fresh-fleet', 'node:role:add ufw-fault', 'node:metrics fresh-fleet', 'node:metrics node-down', 'node:show fresh-fleet']);
});
