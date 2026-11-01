<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireNodeAgentSecret;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Models\Operation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

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
