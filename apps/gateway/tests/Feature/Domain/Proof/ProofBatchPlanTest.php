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
        ->and(array_column($shards[0], 'batch'))->toBe(['first'])
        ->and(array_column($shards[1], 'batch'))->toBe(['second', 'fourth', 'third']);
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
