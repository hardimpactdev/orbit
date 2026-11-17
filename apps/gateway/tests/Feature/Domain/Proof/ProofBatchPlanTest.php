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

it('splits a batch that changes the fleet by case while a place is free and the cut lightens the heaviest copy', function (): void {
    $part = static fn (array $seconds, bool $readOnly = false): array => ['command' => 'node:converge', 'file' => 'converge.cases.yaml', 'batch' => 'fresh',
        'cases' => array_keys($seconds), 'seconds' => array_sum($seconds), 'read_only' => $readOnly, 'case_seconds' => array_values($seconds)];
    $shards = ProofBatchPlan::split([$part(['a' => 100.0, 'b' => 60.0, 'c' => 40.0])], 3);

    // In file order the closest cut is a | b c: 280 s on each copy instead of 380 s on one. Cutting b | c leaves 280 s, so it is not kept.
    expect(array_map(static fn (array $shard): array => array_merge(...array_column($shard, 'cases')), $shards))->toBe([['a'], ['b', 'c']])
        ->and(array_map(ProofBatchPlan::work(...), $shards))->toBe([280.0, 280.0])
        ->and(array_column(array_merge(...$shards), 'case_seconds'))->toBe([[100.0], [60.0, 40.0]])
        // No free place, a read-only batch and a batch of one case stay whole.
        ->and(ProofBatchPlan::split([$part(['a' => 100.0, 'b' => 60.0])], 1))->toHaveCount(1)
        ->and(ProofBatchPlan::split([$part(['a' => 100.0, 'b' => 60.0], true)], 2))->toHaveCount(1)
        ->and(ProofBatchPlan::split([$part(['a' => 100.0])], 2))->toHaveCount(1)
        // A cut of the short batch would not lighten the copy of the long one, so it is not kept.
        ->and(ProofBatchPlan::split([$part(['a' => 10.0, 'b' => 10.0]), $part(['c' => 300.0])], 3))->toHaveCount(2);
});

it('keeps the seconds of each case in the recorded plan, so a retry splits the same way', function (): void {
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches: {fresh: {}}\n");
    $source->shouldReceive('file')->with('head', 'cases.yaml')->andReturn("command: demo:run\ncases: [{id: a, batch: fresh}, {id: b, batch: fresh}, {id: c, batch: fresh}]\n");
    $batches = (new ProofBatchPlan($source))->read('head', [['command' => 'demo:run', 'file' => 'cases.yaml']]);
    $stored = ProofBatchPlan::stored(json_decode(json_encode($batches, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

    expect($batches[0]['case_seconds'])->toBe([1.0, 1.0, 1.0])
        ->and(ProofBatchPlan::split($stored, 2))->toEqual(ProofBatchPlan::split($batches, 2))
        ->and(fn () => ProofBatchPlan::stored([[...$batches[0], 'case_seconds' => [1.0]]]))->toThrow(InvalidArgumentException::class, 'one number for each case');
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
    // These batches carry no case times, so none splits: no plan ends before the longest batch on a copy of its own.
    $floor = max(array_map(static fn (array $batch): float => $copyEnds([$batch]), $batches));

    expect(count($shards))->toBe(10)
        ->and(max($ends))->toBe($floor)
        // The other copies end within one reset and setup of each other: the reset weight keeps short batches from piling up.
        ->and(max(array_diff_key($ends, [array_search($floor, $ends, true) => 0])) - min($ends))->toBeLessThanOrEqual(ProofBatchPlan::BatchSeconds)
        // Five minutes needs these batches shorter: their cases alone are longer than what is left after admission, copy, reset and setup.
        ->and(array_values(array_map(static fn (array $batch): string => $batch['command'].' '.$batch['batch'], array_filter($batches, static fn (array $batch): bool => $copyEnds([$batch]) > $limit))))
        ->toBe(['node:converge fresh-fleet', 'node:converge no-grant', 'node:role:add fresh-fleet', 'node:role:add ufw-fault', 'node:metrics fresh-fleet', 'node:metrics node-down', 'node:show fresh-fleet']);
});

/**
 * One command alone from the push: admission, the copy creates and the commit, then on each copy one reset and setup and its cases.
 * Case seconds measured in a copy after the converged seed and the phase skip: base-scope 43 (79 without a digest record). The other
 * seconds are from proof 25268. required-only of role-add is an estimate: 149 less its dropped repeat converge (49 in a copy).
 * A role Operation skips unchanged phases by the same rule since: an unchanged role convergence took 23 s in a copy (107 before). No planned
 * role-add case repeats a role Operation of the same kind on an unchanged Node, so none gets shorter: required-only adds the role and then
 * converges it once (another kind, so every phase runs), and failure-after-partial-change converges after a failed add (no record). The
 * repeat that skips is application-repeat, which waits for the ID map window.
 * A warm copy (ADR 0260, Warm proof copies) has ended its create before the push: its proof pays admission and the commit, and keeps
 * its first reset, which migrates the nested Gateway on the commit under proof. With a pool of p warm copies only p shards start warm,
 * and a proof does not choose which: so a selection fits only when every shard it plans gets a warm copy.
 */
it('fits node:converge alone, and node:role:add alone when each of its 5 shards gets a warm copy, into five minutes from the push', function (): void {
    $create = 75;
    $cold = 57 + $create + 9;
    $warm = $cold - $create;
    $reset = 60 + 36;
    $part = static fn (string $command, string $batch, array $seconds, bool $readOnly = false): array => ['command' => $command,
        'file' => $command.'.cases.yaml', 'batch' => $batch, 'cases' => array_keys($seconds), 'seconds' => array_sum($seconds),
        'read_only' => $readOnly, 'case_seconds' => array_values($seconds)];
    $ends = static fn (array $shards, int $before): array => array_map(static fn (array $shard): float => $before
        + max(1, count(array_filter($shard, static fn (array $batch): bool => ! $batch['read_only']))) * $reset + array_sum(array_column($shard, 'seconds')), $shards);
    // The cases that wait for the ID map window (default-scope, selected-roles, gateway-authority-without-target-grant,
    // application-repeat) are not planned.
    $converge = [
        $part('node:converge', 'fresh-fleet', ['base-scope' => 43.0]),
        $part('node:converge', 'fresh-fleet', ['refusals' => 11.0], true),
        $part('node:converge', 'no-grant', ['caller-refused' => 2.0]),
        $part('node:converge', 'node-down', ['node-unreachable' => 10.0 /* estimate */]),
    ];
    $roleAdd = [
        $part('node:role:add', 'fresh-fleet', ['required-only' => 100.0 /* estimate */]),
        $part('node:role:add', 'fresh-fleet', ['refusals' => 14.0], true),
        $part('node:role:add', 'ufw-fault', ['failure-after-partial-change' => 96.0]),
        $part('node:role:add', 'node-down', ['unreachable-node' => 30.0 /* estimate */]),
        $part('node:role:add', 'no-grant', ['shared-node-authority' => 60.0 /* estimate */]),
    ];
    // The proof's end with `$pool` warm copies, at worst: the warm copies go to the lightest shards, and the others pay the create.
    $mixed = static function (array $shards, int $pool) use ($ends, $cold, $warm): float {
        $coldEnds = $ends($shards, $cold);
        $warmEnds = $ends($shards, $warm);
        asort($coldEnds);

        return max(array_map(static fn (int $index, int $rank): float => $rank < $pool ? $warmEnds[$index] : $coldEnds[$index],
            array_keys($coldEnds), array_keys(array_keys($coldEnds))));
    };
    $over = static fn (array $batches): array => array_values(array_map(static fn (array $batch): string => $batch['batch'].' '.implode(' ', $batch['cases']),
        array_filter($batches, static fn (array $batch): bool => $ends([[$batch]], $cold)[0] > 300)));

    expect(max($ends(ProofBatchPlan::split($converge, 10), $cold)))->toBeLessThanOrEqual(300.0)
        ->and(max($ends(ProofBatchPlan::split($converge, 10), $cold)))->toBe(280.0)
        // A new copy for each shard keeps role-add over: 237 s of fixed time leaves 63 s for the cases of a copy. The phase skip does not
        // shorten them, since each case runs its role Operations with no record of the same kind.
        ->and(max($ends(ProofBatchPlan::split($roleAdd, 10), $cold)))->toBe(337.0)
        ->and($over($roleAdd))->toBe(['fresh-fleet required-only', 'ufw-fault failure-after-partial-change'])
        // In warm copies 162 s of fixed time leaves 138 s, and the longest part of each command fits.
        ->and(max($ends(ProofBatchPlan::split($roleAdd, 10), $warm)))->toBe(262.0)
        ->and(max($ends(ProofBatchPlan::split($converge, 10), $warm)))->toBe(205.0)
        // Role-add plans 5 shards, so it fits only with 5 warm copies. With fewer, a shard over five minutes may start on a new copy and
        // end as it does today; converge fits with any pool size.
        ->and(ProofBatchPlan::split($roleAdd, 10))->toHaveCount(5)
        ->and($mixed(ProofBatchPlan::split($roleAdd, 10), 5))->toBe(262.0)
        ->and($mixed(ProofBatchPlan::split($roleAdd, 10), 4))->toBe(337.0)
        ->and($mixed(ProofBatchPlan::split($roleAdd, 10), 0))->toBe(337.0)
        ->and($mixed(ProofBatchPlan::split($converge, 10), 0))->toBe(280.0);
});

it('never plans a case that waits for a named reason, and refuses a wait without one', function (): void {
    // docs/reference/verification-cases: nested-app-dev's roles phase waits for the 2,000,000 ID map window.
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches: {fresh: {}, other: {}}\n");
    $source->shouldReceive('file')->with('head', 'cases.yaml')->andReturn("command: demo:run\nwaits: {window: The owner schedules it.}\ncases: [{id: a, batch: fresh}, {id: b, batch: fresh, waits: window}, {id: c, batch: other, waits: window}]\n");
    $source->shouldReceive('file')->with('head', 'unnamed.yaml')->andReturn("command: demo:run\ncases: [{id: a, batch: fresh}, {id: b, batch: fresh, waits: window}]\n");
    $plan = new ProofBatchPlan($source);

    expect($plan->read('head', [['command' => 'demo:run', 'file' => 'cases.yaml']]))->toHaveCount(1)
        ->and($plan->read('head', [['command' => 'demo:run', 'file' => 'cases.yaml']])[0])->toMatchArray(['batch' => 'fresh', 'cases' => ['a']])
        ->and(fn () => $plan->read('head', [['command' => 'demo:run', 'file' => 'unnamed.yaml']]))->toThrow(ResourceOperationException::class, 'waits for no named reason');
});
