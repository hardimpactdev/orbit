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
    $source->shouldReceive('file')->with('head', 'swamp/proof/batches.yaml')->andReturn("batches:\n  first: {}\n  second: {}\n  third: {}\n");
    $source->shouldReceive('file')->with('head', $file)->andReturn("command: firewall:allow\ncases:\n  - {id: a, batch: first}\n  - {id: b, batch: second, variants: [{name: v4}, {name: v6}]}\n  - {id: c, batch: third}\n");

    $batches = (new ProofBatchPlan($source))->read('head', [['command' => 'firewall:allow', 'file' => $file]]);
    $shards = ProofBatchPlan::split($batches, 2);

    expect(array_column($batches, 'seconds'))->toBe([20.0, 13.0, 5.0])
        ->and($batches[1]['cases'])->toBe(['b[v4]', 'b[v6]'])
        ->and(array_column($shards[0], 'batch'))->toBe(['first'])
        ->and(array_column($shards[1], 'batch'))->toBe(['second', 'third']);
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
