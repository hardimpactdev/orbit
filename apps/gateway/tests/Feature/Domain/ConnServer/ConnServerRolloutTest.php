<?php

declare(strict_types=1);

use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Fleet\AdmitConnServerRolloutRequest;
use App\Models\ConnServerNode;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** A Node that runs a Conn Server, with its record. @param array<string, mixed> $server */
function connServerNode(string $name, int $address, array $server): Node
{
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => '192.0.2.'.$address,
        'wireguard_ip' => '10.44.0.'.$address, 'user' => 'nick', 'ssh_host_fingerprint' => 'SHA256:'.$name, 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => $name.'-key', 'status' => LifecycleStatus::Active, 'platform' => 'darwin', 'architecture' => 'arm64']));
    ConnServerNode::query()->create(['node_id' => $node->id, 'platform' => 'mac', 'path' => '~/Applications/Conn Server.app',
        'service' => 'com.nckrtl.conn-server', 'port' => 3773, 'user' => 'nick', ...$server]);

    return $node;
}

/** Serve the shipped recipe, with `$change` applied to its decoded document. */
function connServerPin(?Closure $change = null): void
{
    $recipe = json_decode((string) file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    if ($change !== null) {
        $change($recipe);
    }
    $home = sys_get_temp_dir().'/conn-server-rollout-'.bin2hex(random_bytes(4));
    mkdir($home.'/release/apps/gateway/resources/machine-software', 0700, true);
    symlink('release', $home.'/orbit');
    file_put_contents($home.'/orbit/apps/gateway/resources/machine-software/recipe.json', json_encode($recipe, JSON_THROW_ON_ERROR));
    config(['orbit.gateway_checkout' => $home.'/orbit/apps/gateway']);
}

beforeEach(function (): void {
    Queue::fake();
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    SwampNodeFixture::ensure();
});

it('admits the pinned release for every Conn Server Node, canary first, from their records', function (): void {
    connServerPin();
    $mini = connServerNode('mini', 61, ['service' => 'com.t3tools.t3code.service', 'notify_before_restart' => true]);
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);

    $operation = app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet');
    $intent = $operation->input['intent'];

    expect($operation->kind)->toBe('fleet.conn_server_rollout')
        ->and(app(DirectOperation::class)->definition('fleet.conn_server_rollout')['workflow'])->toBe('operation-fleet-conn-server-rollout')
        ->and($intent['version'])->toBe('0.5.1')
        ->and($intent['dry_run'])->toBeTrue()
        ->and($intent['mac'])->toBe(['url' => 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.1/conn-server-0.5.1-macos-arm64.zip',
            'sha256' => '55d20b2d01e6e3f9b1fc2491fa98cf3245208bb98295bd14faf96b47b98f32bd', 'bundle_id' => 'com.nckrtl.conn-server', 'team_id' => '9SVJ4SYB9B'])
        ->and($intent['linux'])->toBe(['url' => 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.1/conn-server-0.5.1-linux-x64.tar.gz',
            'sha256' => '13e71798c9dc672f2b3d11aa28c74fb8f0748ffe3ce7d4a54dc7eab79e3fe124'])
        ->and($intent['notice'])->toBe(['url' => null, 'command' => null])
        ->and(array_column($intent['nodes'], 'node_id'))->toBe([$vm->id, $mini->id])
        ->and($intent['nodes'][1]['server'])->toBe(['platform' => 'mac', 'path' => '~/Applications/Conn Server.app', 'service' => 'com.t3tools.t3code.service',
            'port' => 3773, 'user' => 'nick', 'canary' => false, 'notify_before_restart' => true])
        ->and($intent['nodes'][0]['ssh']['target'])->toMatchArray(['host' => '10.44.0.62', 'user' => 'nick', 'host_keys' => ['ssh-ed25519 nick-vm-key']]);
});

it('passes the configured notice target to the run', function (): void {
    connServerPin();
    connServerNode('nick-vm', 62, ['canary' => true]);
    config(['orbit.conn.server_rollout_notice' => ['url' => 'https://mini.example/notice', 'command' => null]]);

    expect(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'fleet')->input['intent']['notice'])->toBe(['url' => 'https://mini.example/notice', 'command' => null]);
});

it('refuses a pin without a macOS app, and a fleet without exactly one canary', function (): void {
    connServerNode('nick-vm', 62, ['canary' => true]);
    connServerPin(static function (array &$recipe): void {
        unset($recipe['instances']['agent']['conn-server']['macos_arm64']);
    });
    expect(fn () => app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet'))->toThrow(ResourceOperationException::class, 'The Conn Server pin names no macOS arm64 app.');

    connServerPin();
    ConnServerNode::query()->update(['canary' => false]);
    expect(fn () => app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet'))->toThrow(ResourceOperationException::class, 'Exactly one Conn Server Node must be the canary.');
});

it('reaches only the canary with scope canary, and keeps the canary-first order with scope fleet', function (): void {
    connServerPin();
    $nick = connServerNode('nick', 60, ['service' => 'com.nckrtl.t3-headless', 'port' => 3780]);
    $mini = connServerNode('mini', 61, ['service' => 'com.t3tools.t3code.service', 'notify_before_restart' => true]);
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);

    expect(array_column(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'canary')->input['intent']['nodes'], 'node_id'))->toBe([$vm->id])
        ->and(array_column(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'fleet')->input['intent']['nodes'], 'node_id'))->toBe([$vm->id, $nick->id, $mini->id]);
});

it('refuses a trigger that names no scope or another one, and no dry run choice', function (): void {
    $rules = new AdmitConnServerRolloutRequest()->rules();

    expect(Validator::make(['dry_run' => false], $rules)->errors()->keys())->toBe(['scope'])
        ->and(Validator::make(['dry_run' => false, 'scope' => 'all'], $rules)->errors()->keys())->toBe(['scope'])
        ->and(Validator::make(['scope' => 'canary'], $rules)->errors()->keys())->toBe(['dry_run'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'canary'], $rules)->passes())->toBeTrue();
});
