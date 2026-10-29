<?php

declare(strict_types=1);

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireNodeAgentSecret;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Models\NodeFootprint;
use App\Models\Operation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

require_once __DIR__.'/../../Support/Fleet/NodeSecretOutput.php';

it('stores the actual footprint configure hash encrypted and projects only its owner reference', function (): void {
    [$node, $operation, $path, $run] = nodeSecretOutput($this);
    $hash = hash('sha256', 'new-disposable-agent-secret');
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (array $input) use ($hash): array {
        $metadata = $input[EffectReceiptMetadata::InputKey];
        expect($metadata->envelope()['secret_outputs'])->toBe(['secret_hash'])
            ->and($input['secret_hash'])->toBe(str_repeat('a', 64));

        return ['secret_hash' => $hash, 'restarted' => true, 'unclosed' => []];
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $reference = ['$secret' => 'node.agent_secret_hash', 'id' => $node->id];
    $result = $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data.secret_hash', $reference)->json('data');
    $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data', $result);
    expect($agents->commands())->toBe(['agent.configure'])
        ->and($node->fresh()->agent_secret_hash)->toBe($hash)
        ->and(DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash'))->not->toContain($hash)
        ->and(json_encode($operation->fresh()->getAttributes()))->not->toContain($hash, str_repeat('a', 64));
    $this->postJson($path.'/step', ['worker' => 'hash-worker', 'key' => 'configured', 'name' => 'footprint-agent',
        'status' => 'succeeded', 'result' => $result])->assertOk();
    expect(NodeFootprint::query()->where('node_id', $node->id)->exists())->toBeFalse()
        ->and(json_encode($operation->fresh()->steps()->get()->toArray()))->not->toContain($hash)
        ->and($node->fresh()->toArray())->not->toHaveKey('agent_secret_hash');
});

it('keeps a lost configure reply uncertain when the target proves presence without the hash', function (): void {
    [$node, $operation, $path, $run] = nodeSecretOutput($this);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', function (): array {
        throw new RuntimeException('Lost configure response');
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $identity = $operation->fresh()->state['executor_effect_identity'];
    $agents->answer('agent.effect-receipt', ['command' => 'agent.configure', 'effect_key' => 'op-'.$operation->id.'.footprint-agent',
        'state' => 'succeeded', 'sanitized' => true, 'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'],
        'exit_code' => 0, 'finished_at' => '2026-10-29T02:00:00Z', 'result_digest' => str_repeat('c', 64),
        'result' => ['ok' => true, 'data' => ['secret_hash' => ['$secret_output' => true], 'restarted' => true, 'unclosed' => []]]]);
    $this->getJson($path.'/receipts/footprint-agent')->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $this->postJson($path.'/run', $run)->assertOk()->assertJsonPath('data.error_code', 'operation.execution_uncertain');
    $this->postJson($path.'/complete', ['worker' => 'hash-worker', 'status' => 'succeeded', 'result' => (object) []])->assertConflict();
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64))
        ->and($operation->fresh()->state['executor_runs']['footprint-agent']['result'])->toBeNull()
        ->and(NodeFootprint::query()->where('node_id', $node->id)->exists())->toBeFalse()
        ->and(array_count_values($agents->commands())['agent.configure'])->toBe(1);
});

it('authorizes the Node hash store against the exact operation and owner', function (string $variant): void {
    $node = FleetFixtures::node('authorized-owner');
    $other = FleetFixtures::node('foreign-owner');
    $operation = (new Operation)->forceFill(['kind' => 'node.converge', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id]);
    $owner = $node->id;
    $value = str_repeat('b', 64);
    match ($variant) {
        'kind' => $operation->kind = 'node.settings',
        'type' => $operation->target_type = 'instance',
        'target' => $operation->target_id = $other->id,
        'owner' => $owner = $other->id,
        'missing' => $operation->node_id = null,
        'value' => $value = ['$secret_output' => true],
    };
    expect(fn () => new NodeAgentHashSource()->store($operation, $owner, $value))->toThrow(ResourceOperationException::class);
    expect($node->fresh()->agent_secret_hash)->toBeNull()->and($other->fresh()->agent_secret_hash)->toBeNull();
})->with(['kind', 'type', 'target', 'owner', 'missing', 'value']);

it('encrypts historical Node hash rows without changing their accepted value', function (): void {
    $node = FleetFixtures::node('historical-hash');
    $hash = hash('sha256', 'old-disposable-agent-secret');
    DB::table('nodes')->where('id', $node->id)->update(['agent_secret_hash' => $hash]);
    $migration = require database_path('migrations/2027_01_07_000000_encrypt_node_agent_secret_hashes.php');
    $migration->up();
    $stored = DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash');
    expect($stored)->not->toContain($hash)->and(Crypt::decryptString($stored))->toBe($hash)
        ->and($node->fresh()->agent_secret_hash)->toBe($hash);
});

it('preserves agent authentication through migration rollback and roll-forward', function (): void {
    $node = FleetFixtures::node('rollback-hash');
    $secret = 'disposable-rollback-secret';
    $hash = hash('sha256', $secret);
    $node->forceFill(['agent_secret_hash' => $hash])->save();
    $migration = require database_path('migrations/2027_01_07_000000_encrypt_node_agent_secret_hashes.php');
    $authorize = function () use ($node, $secret): void {
        $request = Request::create('/api/v1/agent/status', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        $request->setUserResolver(fn () => $node->fresh());
        expect(app(RequireNodeAgentSecret::class)->handle($request, fn () => response('accepted'))->getStatusCode())->toBe(200);
    };
    $migration->up();
    $authorize();
    $migration->down();
    expect(DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash'))->toBe($hash);
    $authorize();
    $migration->up();
    $authorize();
    $migration->up();
    expect($node->fresh()->agent_secret_hash)->toBe($hash);
});

it('accepts only exact plaintext SHA-256 values from a rollback-era writer', function (): void {
    $node = FleetFixtures::node('mixed-hash');
    $hash = str_repeat('d', 64);
    DB::table('nodes')->where('id', $node->id)->update(['agent_secret_hash' => $hash]);
    expect($node->fresh()->agent_secret_hash)->toBe($hash);
    $node->fresh()->forceFill(['agent_secret_hash' => $hash])->save();
    expect(DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash'))->not->toBe($hash);
    DB::table('nodes')->where('id', $node->id)->update(['agent_secret_hash' => 'malformed']);
    expect(fn () => $node->fresh()->agent_secret_hash)->toThrow(DecryptException::class);
});
