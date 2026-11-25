<?php

declare(strict_types=1);

use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Fleet\AdmitConnServerRolloutRequest;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\ConnServerNode;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
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
    // A fixed release fixture lets the production recipe advance independently.
    $release = &$recipe['instances']['agent']['conn-server'];
    unset($release['release_canary']);
    $release['version'] = '0.5.2';
    $base = 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.2/conn-server-0.5.2';
    $release['artifacts']['amd64'] = ['url' => $base.'-linux-x64.tar.gz', 'sha256' => str_repeat('a', 64)];
    $release['macos_arm64']['url'] = $base.'-macos-arm64.zip';
    $release['macos_arm64']['sha256'] = str_repeat('b', 64);
    $release['bin']['conn-server'] = 'conn-server-0.5.2-linux-x64/conn-server';
    if ($change !== null) {
        $change($recipe);
    }
    $home = sys_get_temp_dir().'/conn-server-rollout-'.bin2hex(random_bytes(4));
    test()->recipeHomes = [...test()->recipeHomes, $home];
    mkdir($home.'/releases/'.str_repeat('a', 12).'/apps/gateway/resources/machine-software', 0700, true);
    symlink('releases/'.str_repeat('a', 12), $home.'/orbit');
    file_put_contents($home.'/orbit/apps/gateway/resources/machine-software/recipe.json', json_encode($recipe, JSON_THROW_ON_ERROR));
    config(['orbit.gateway_checkout' => $home.'/orbit/apps/gateway']);
    test()->mock(GatewayReleaseObserver::class)->shouldReceive('inspect')->andReturn(
        new GatewayReleaseInventory(null, 'in_place', false, [], [], []),
    );

}

beforeEach(function (): void {
    $this->recipeHomes = [];
    Queue::fake();
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    SwampNodeFixture::ensure();
});

afterEach(function (): void {
    foreach ($this->recipeHomes as $home) {
        File::deleteDirectory($home);
    }
});

it('admits the pinned release for every Conn Server Node, canary first, from their records', function (): void {
    connServerPin();
    $mini = connServerNode('mini', 61, ['service' => 'com.t3tools.t3code.service', 'notify_before_restart' => true]);
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);

    $operation = app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet');
    $intent = $operation->input['intent'];

    expect($operation->kind)->toBe('fleet.conn_server_rollout')
        ->and(app(DirectOperation::class)->definition('fleet.conn_server_rollout')['workflow'])->toBe('operation-fleet-conn-server-rollout')
        ->and($intent['version'])->toBe('0.5.2')
        ->and($intent['dry_run'])->toBeTrue()
        ->and($intent['mac'])->toBe(['url' => 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.2/conn-server-0.5.2-macos-arm64.zip',
            'sha256' => str_repeat('b', 64), 'bundle_id' => 'com.nckrtl.conn-server', 'team_id' => '9SVJ4SYB9B'])
        ->and($intent['linux'])->toBe(['url' => 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.2/conn-server-0.5.2-linux-x64.tar.gz',
            'sha256' => str_repeat('a', 64)])
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

    $canary = app(ConnServerRollout::class)->admit(dryRun: false, scope: 'canary');
    $canary->forceFill(['status' => 'succeeded', 'lock_key' => null])->save();

    expect(array_column($canary->input['intent']['nodes'], 'node_id'))->toBe([$vm->id])
        ->and(array_column(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'fleet')->input['intent']['nodes'], 'node_id'))->toBe([$vm->id, $nick->id, $mini->id]);
});

it('reaches only the named Nodes with scope nodes, in the order given, without the canary', function (): void {
    connServerPin();
    $nick = connServerNode('nick', 60, ['service' => 'com.nckrtl.t3-headless', 'port' => 3780]);
    $mini = connServerNode('mini', 61, ['service' => 'com.t3tools.t3code.service', 'notify_before_restart' => true]);
    connServerNode('nick-vm', 62, ['canary' => true]);
    $agent = connServerNode('agent', 63, ['platform' => 'linux', 'path' => '/opt/conn-server/current', 'service' => 'conn-server', 'user' => 'conn']);

    $nodes = app(ConnServerRollout::class)->admit(dryRun: true, scope: 'nodes', nodeIds: [$agent->id, $mini->id])->input['intent']['nodes'];

    expect(array_column($nodes, 'node_id'))->toBe([$agent->id, $mini->id])
        ->and(array_column($nodes, 'node_id'))->not->toContain($nick->id)
        ->and($nodes[0]['server'])->toMatchArray(['platform' => 'linux', 'user' => 'conn', 'canary' => false])
        ->and($nodes[1]['server']['notify_before_restart'])->toBeTrue();
});

it('admits node_ids only with scope nodes, each with a Conn Server record', function (): void {
    $rules = new AdmitConnServerRolloutRequest()->rules();
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);
    $mini = connServerNode('mini', 61, []);
    $bare = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'bare', 'public_ssh_host' => '192.0.2.70', 'wireguard_ip' => '10.44.0.70',
        'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:bare', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'bare-key',
        'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));

    expect(Validator::make(['dry_run' => true, 'scope' => 'nodes', 'node_ids' => [$mini->id, $vm->id]], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['dry_run' => true, 'scope' => 'nodes'], $rules)->errors()->keys())->toBe(['node_ids'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'nodes', 'node_ids' => []], $rules)->errors()->keys())->toBe(['node_ids'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'nodes', 'node_ids' => [$mini->id, $bare->id]], $rules)->errors()->keys())->toBe(['node_ids.1'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'nodes', 'node_ids' => [999999]], $rules)->errors()->keys())->toBe(['node_ids.0'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'nodes', 'node_ids' => [$mini->id, $mini->id]], $rules)->errors()->keys())->toBe(['node_ids.0', 'node_ids.1'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'nodes', 'node_ids' => [1, 2, 3, 4, 5]], $rules)->errors()->keys())->toContain('node_ids')
        ->and(Validator::make(['dry_run' => true, 'scope' => 'fleet', 'node_ids' => [$mini->id]], $rules)->errors()->keys())->toBe(['node_ids'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'canary', 'node_ids' => [$vm->id]], $rules)->errors()->keys())->toBe(['node_ids']);
});

it('refuses a trigger that names no scope or another one, and no dry run choice', function (): void {
    $rules = new AdmitConnServerRolloutRequest()->rules();

    expect(Validator::make(['dry_run' => false], $rules)->errors()->keys())->toBe(['scope'])
        ->and(Validator::make(['dry_run' => false, 'scope' => 'all'], $rules)->errors()->keys())->toBe(['scope'])
        ->and(Validator::make(['scope' => 'canary'], $rules)->errors()->keys())->toBe(['dry_run'])
        ->and(Validator::make(['dry_run' => true, 'scope' => 'canary'], $rules)->passes())->toBeTrue();
});

function finishConnRollout(Operation $operation, string $status, array $nodes): void
{
    $operation->forceFill(['status' => $status, 'lock_key' => null, 'result' => [
        'version' => $operation->input['intent']['version'],
        'dry_run' => $operation->input['intent']['dry_run'],
        'nodes' => $nodes,
    ]])->save();
}

it('replays the first keyed canary receipt after its dry run passes without dispatching an update', function (): void {
    connServerPin(static function (array &$recipe): void {
        $recipe['instances']['agent']['conn-server']['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $url = '/api/v1/fleet/conn-server/rollout';
    $body = ['scope' => 'canary', 'dry_run' => false];
    $key = ['Idempotency-Key' => 'canary-rollout'];
    $secondKey = ['Idempotency-Key' => 'canary-rollout-also'];

    $response = $this->postJson($url, $body, $key)->assertAccepted();
    $check = Operation::query()->sole();
    $response->assertJsonPath('data.id', $check->id);
    expect($check->input['intent']['dry_run'])->toBeTrue();
    $this->postJson($url, $body, $secondKey)->assertAccepted()->assertJsonPath('data.id', $check->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);

    $replay = $this->postJson($url, $body, $key)->assertAccepted();
    expect(Operation::query()->count())->toBe(1);
    $replay->assertJsonPath('data.id', $check->id);
    $this->postJson($url, $body, $secondKey)->assertAccepted()->assertJsonPath('data.id', $check->id);
    $this->postJson($url, ['scope' => 'canary', 'dry_run' => true], $key)
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    expect(Operation::query()->count())->toBe(1);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    $fresh = $this->postJson($url, $body, ['Idempotency-Key' => 'canary-update'])->assertAccepted();
    $update = Operation::query()->whereKeyNot($check->id)->sole();
    $fresh->assertJsonPath('data.id', $update->id);
    expect($update->input['intent']['dry_run'])->toBeFalse();
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('skips an updated installation in a promotion retry after follower settings change', function (bool $changeSsh): void {
    connServerPin(static function (array &$recipe): void {
        $recipe['instances']['agent']['conn-server']['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    $updated = connServerNode('updated', 61, ['notify_before_restart' => true]);
    $failed = connServerNode('failed', 60, []);
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    $check = $rollout->releaseGate($pin);
    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    finishConnRollout($probe, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true]]);
    $preflight = $rollout->admit(true, 'fleet');
    $checked = [['node_id' => $updated->id, 'outcome' => 'checked'], ['node_id' => $failed->id, 'outcome' => 'checked']];
    finishConnRollout($preflight, 'succeeded', $checked);
    $promotion = $rollout->admit(false, 'fleet', canaryOperationId: $probe->id);
    finishConnRollout($promotion, 'failed', [
        ['node_id' => $updated->id, 'outcome' => 'updated'],
        ['node_id' => $failed->id, 'outcome' => 'failed', 'changed' => false],
    ]);

    if ($changeSsh) {
        $updated->update(['user' => 'operator']);
    } else {
        ConnServerNode::query()->where('node_id', $updated->id)->update(['notify_before_restart' => false]);
    }
    $recheck = $rollout->admit(true, 'fleet', retryOperationId: $preflight->id);
    $field = $changeSsh ? 'ssh.target.user' : 'server.notify_before_restart';
    expect(data_get($recheck->input['intent']['nodes'][0], $field))->toBe($changeSsh ? 'operator' : false);
    finishConnRollout($recheck, 'succeeded', $checked);

    $retry = $rollout->admit(false, 'fleet', canaryOperationId: $probe->id, retryOperationId: $promotion->id);
    expect(array_column($retry->input['intent']['nodes'], 'node_id'))->toBe([$failed->id]);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $retry->id);
})->with(['notification setting' => [false], 'SSH user' => [true]]);

it('reads the standalone Conn pin without resolving the Orbit Agent delivery manifest', function (): void {
    connServerPin(static function (array &$recipe): void {
        $recipe['instances']['agent']['conn-server']['release_canary'] = true;
    });
    app()->instance(AgentDelivery::class, new AgentDelivery(config()->string('orbit.gateway_checkout').'/missing-manifest.json', '/unused'));

    $pin = app(ConnServerRollout::class)->releasePin();

    expect($pin?->version)->toBe('0.5.2')
        ->and($pin?->releaseCanary)->toBeTrue();
});

it('records a follower SSH blocker without invalidating the verified canary', function (): void {
    connServerPin(static function (array &$recipe): void {
        $recipe['instances']['agent']['conn-server']['release_canary'] = true;
    });
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);
    $other = connServerNode('mini', 61, []);
    $other->forceFill(['ssh_host_key' => null, 'ssh_host_fingerprint' => null])->save();

    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    $check = $rollout->releaseGate($pin);
    finishConnRollout($check, 'succeeded', [['node_id' => $vm->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    finishConnRollout($probe, 'succeeded', [['node_id' => $vm->id, 'outcome' => 'updated', 'thread_read' => true]]);
    expect($rollout->releaseGate($pin))->toBeNull();

    $preflight = Operation::query()->where('input->release_stage', 'preflight')->sole();
    expect($preflight->result['error_code'])->toBe('conn_server.preflight_blocked')
        ->and($preflight->result['nodes'][0])->toMatchArray(['node_id' => $other->id, 'outcome' => 'held']);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))
        ->toThrow(ResourceOperationException::class, 'Review the held Nodes');
});

it('resumes a pin from its durable receipts and authorizes exactly one promotion to the remaining Nodes', function (): void {
    connServerPin(static function (array &$recipe): void {
        $recipe['instances']['agent']['conn-server']['release_canary'] = true;
    });
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);
    $mini = connServerNode('mini', 61, ['notify_before_restart' => true]);
    $nick = connServerNode('nick', 60, []);
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    $check = $rollout->releaseGate($pin);
    expect($check->input['intent']['dry_run'])->toBeTrue()
        ->and($rollout->releaseGate($pin)->id)->toBe($check->id);
    finishConnRollout($check, 'succeeded', [['node_id' => $vm->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    expect($probe->input['intent']['dry_run'])->toBeFalse()
        ->and($probe->input['intent']['probe_thread'])->toBeTrue();
    finishConnRollout($probe, 'failed', [['node_id' => $vm->id, 'outcome' => 'failed', 'rolled_back' => true]]);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))->toThrow(ResourceOperationException::class)
        ->and($rollout->releaseGate($pin)->id)->toBe($probe->id);

    $retry = $rollout->admit(false, 'canary', retryOperationId: $probe->id);
    expect($retry->id)->not->toBe($probe->id)
        ->and($rollout->admit(false, 'canary', retryOperationId: $probe->id)->id)->toBe($retry->id);
    finishConnRollout($retry, 'succeeded', [['node_id' => $vm->id, 'outcome' => 'updated', 'thread_read' => true]]);
    expect($rollout->releaseGate($pin))->toBeNull()
        ->and(app(ConnServerRollout::class)->releaseGate($pin))->toBeNull();
    $preflight = Operation::query()->where('input->release_stage', 'preflight')->sole();
    expect($preflight->input['intent']['dry_run'])->toBeTrue()
        ->and(array_column($preflight->input['intent']['nodes'], 'node_id'))->toBe([$mini->id, $nick->id]);
    finishConnRollout($preflight, 'failed', [['node_id' => $mini->id, 'outcome' => 'failed'], ['node_id' => $nick->id, 'outcome' => 'checked']]);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $retry->id))->toThrow(ResourceOperationException::class, 'Repeat the fleet dry run');

    $recheck = $rollout->admit(true, 'fleet', retryOperationId: $preflight->id);
    finishConnRollout($recheck, 'succeeded', [['node_id' => $mini->id, 'outcome' => 'checked'], ['node_id' => $nick->id, 'outcome' => 'checked']]);
    $promotion = $rollout->admit(false, 'fleet', canaryOperationId: $retry->id);
    expect(array_column($promotion->input['intent']['nodes'], 'node_id'))->toBe([$mini->id, $nick->id])
        ->and($promotion->input['intent']['nodes'][0]['server']['notify_before_restart'])->toBeTrue()
        ->and(app(ConnServerRollout::class)->admit(false, 'fleet', canaryOperationId: $retry->id)->id)->toBe($promotion->id);
    finishConnRollout($promotion, 'failed', [
        ['node_id' => $mini->id, 'outcome' => 'updated'],
        ['node_id' => $nick->id, 'outcome' => 'failed', 'rolled_back' => true, 'changed' => true],
    ]);
    $continued = $rollout->admit(false, 'fleet', canaryOperationId: $retry->id, retryOperationId: $promotion->id);
    expect(array_column($continued->input['intent']['nodes'], 'node_id'))->toBe([$nick->id])
        ->and($rollout->admit(false, 'fleet', canaryOperationId: $retry->id, retryOperationId: $promotion->id)->id)->toBe($continued->id);
    finishConnRollout($continued, 'failed', [['node_id' => $nick->id, 'outcome' => 'updated']]);
    ConnServerNode::query()->where('node_id', $mini->id)->update([
        'path' => '~/Applications/Replacement Conn Server.app', 'service' => 'com.nckrtl.conn-server.replacement',
    ]);
    $newCheck = $rollout->admit(true, 'fleet', retryOperationId: $recheck->id);
    finishConnRollout($newCheck, 'succeeded', [['node_id' => $mini->id, 'outcome' => 'checked'], ['node_id' => $nick->id, 'outcome' => 'checked']]);
    $replacement = $rollout->admit(false, 'fleet', canaryOperationId: $retry->id, retryOperationId: $continued->id);
    expect(array_column($replacement->input['intent']['nodes'], 'node_id'))->toBe([$mini->id])
        ->and($replacement->input['intent']['nodes'][0]['server']['service'])->toBe('com.nckrtl.conn-server.replacement');
    finishConnRollout($replacement, 'failed', [['node_id' => $mini->id, 'outcome' => 'updated']]);
    $completed = $rollout->admit(false, 'fleet', canaryOperationId: $retry->id, retryOperationId: $replacement->id);
    expect($completed->status->value)->toBe('succeeded')
        ->and($completed->state['swamp_run_id'] ?? null)->toBeNull()
        ->and($completed->input['intent']['nodes'])->toBe([])
        ->and($rollout->admit(false, 'fleet', canaryOperationId: $retry->id)->id)->toBe($completed->id);

    connServerPin(static function (array &$recipe): void {
        $pin = &$recipe['instances']['agent']['conn-server'];
        $pin['release_canary'] = true;
        $pin['artifacts']['amd64']['sha256'] = str_repeat('c', 64);
    });
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $retry->id))->toThrow(ResourceOperationException::class, 'successful canary_operation_id')
        ->and($rollout->releaseGate($rollout->releasePin())->id)->not->toBe($check->id);
});
