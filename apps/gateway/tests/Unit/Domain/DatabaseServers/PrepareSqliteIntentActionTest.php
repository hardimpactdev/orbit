<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareSqliteIntentAction;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->prepare = new PrepareSqliteIntentAction;
    $this->sourceNode = (new Node)->forceFill(['id' => 12, 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.12']);
    $this->targetNode = (new Node)->forceFill(['id' => 17, 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17']);
});

it('records one public source and target descriptor without nested intents', function (): void {
    $source = new SqliteSeedPlacement(2, 'development', '/home/orbit/source', 'orbit', $this->sourceNode, 'transfer-1');
    $target = new SqliteSeedPlacement(2, 'development', '/home/orbit/target', 'orbit', $this->targetNode, 'transfer-1');
    $input = $this->prepare->seed($source, $target, '/home/orbit/source/db.sqlite');

    expect($input['participant_node_ids'])->toBe([12, 17])

        ->and($input['target']['operation'])->toBe($input['source']['operation'])
        ->and($input['target']['relative_path'])->toBe('db.sqlite')
        ->and($input)->not->toHaveKeys(['children', 'intent_id', 'commands', 'receipts']);
});

it('refuses an escaped source path or missing development transfer attempt', function (string $invalid): void {
    $attempt = $invalid === 'attempt' ? null : 'transfer-1';
    $source = new SqliteSeedPlacement(2, 'development', '/home/orbit/source', 'orbit', $this->sourceNode, $attempt);
    $target = new SqliteSeedPlacement(2, 'development', '/home/orbit/target', 'orbit', $this->targetNode, $attempt);
    $path = $invalid === 'path' ? '/home/orbit/source/../foreign.sqlite' : '/home/orbit/source/db.sqlite';

    expect(fn () => $this->prepare->seed($source, $target, $path))->toThrow(ResourceOperationException::class);
})->with(['path', 'attempt']);

it('derives abandon from the owning recorded transfer seed and refuses another Instance', function (): void {
    $source = new SqliteSeedPlacement(2, 'development', '/home/orbit/source', 'orbit', $this->sourceNode, 'transfer-1');
    $target = new SqliteSeedPlacement(2, 'development', '/home/orbit/target', 'orbit', $this->targetNode, 'transfer-1');
    $input = $this->prepare->seed($source, $target, '/home/orbit/source/db.sqlite');
    $seed = (new Operation)->forceFill(['kind' => 'database.sqlite_seed', 'target_type' => 'instance', 'target_id' => 2, 'node_id' => 17, 'input' => $input]);

    expect($this->prepare->abandon((new Instance)->forceFill(['id' => 2]), $seed))->toBe($input);
    expect(fn () => $this->prepare->abandon((new Instance)->forceFill(['id' => 3]), $seed))->toThrow(ResourceOperationException::class);
});
