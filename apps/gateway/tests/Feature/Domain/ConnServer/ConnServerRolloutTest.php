<?php

declare(strict_types=1);

use App\Actions\Tools\ManageToolPolicyAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\ConnServer\ConnServerRolloutProjection;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolOperationException;
use App\Http\Requests\Fleet\AdmitConnServerRolloutRequest;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\ConnServerNode;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TestOrbitHome;

uses(LazilyRefreshDatabase::class);

/** A Node that runs a Conn Server, with its record. @param array<string, mixed> $server */
function connServerNode(string $name, int $address, array $server): Node
{
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => '192.0.2.'.$address,
        'wireguard_ip' => '10.44.0.'.$address, 'user' => 'nick', 'ssh_host_fingerprint' => 'SHA256:'.$name, 'ssh_host_key_type' => 'ssh-ed25519',
        'ssh_host_key' => $name.'-key', 'status' => LifecycleStatus::Active, 'platform' => 'macos', 'architecture' => 'arm64']));
    ConnServerNode::query()->create(['node_id' => $node->id, 'platform' => 'mac', 'path' => '~/Applications/Conn Server.app',
        'service' => 'com.nckrtl.conn-server', 'port' => 3773, 'user' => 'nick', ...$server]);

    return $node;
}

/** Serve a stable host catalog independently of shipped artifact changes. */
function connServerPin(?Closure $change = null): void
{
    $catalog = Yaml::parseFile(resource_path('machine-software/conn-server-releases.yaml'));
    $catalog['default'] = '0.5.2';
    $release = &$catalog['releases']['0.5.2'];
    unset($release['release_canary']);
    $release['linux_x64']['sha256'] = str_repeat('a', 64);
    $release['macos_arm64']['sha256'] = str_repeat('b', 64);
    if ($change !== null) {
        $change($release);
    }
    serveConnServerCatalog(Yaml::dump($catalog, 6));
}

function serveConnServerCatalog(string $catalog): void
{
    $home = sys_get_temp_dir().'/conn-server-rollout-'.bin2hex(random_bytes(4));
    test()->recipeHomes = [...test()->recipeHomes, $home];
    mkdir($home.'/releases/'.str_repeat('a', 12).'/apps/gateway/resources/machine-software', 0700, true);
    symlink('releases/'.str_repeat('a', 12), $home.'/orbit');
    file_put_contents($home.'/orbit/apps/gateway/resources/machine-software/conn-server-releases.yaml', $catalog);
    config(['orbit.gateway_checkout' => $home.'/orbit/apps/gateway']);
}

function enrollConnServer(Node $node, string $version = '0.5.2'): Tool
{
    $result = app(StartToolOperationAction::class)->execute('install', $node->id, 'conn-server', 'conn-server', null, version: $version)->result;

    return Tool::query()->findOrFail($result['tool_id']);
}

beforeEach(function (): void {
    $this->recipeHomes = [];
    connServerPin();
    $this->mock(GatewayReleaseObserver::class)->shouldReceive('inspect')->andReturn(
        new GatewayReleaseInventory(null, 'in_place', false, [], [], []),
    );
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
    $mini = connServerNode('mini', 61, ['service' => 'com.t3tools.t3code.service', 'notify_before_restart' => true]);
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);

    $operation = app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet', version: '0.5.2');
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
    connServerNode('nick-vm', 62, ['canary' => true]);
    config(['orbit.conn.server_rollout_notice' => ['url' => 'https://mini.example/notice', 'command' => null]]);

    expect(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'fleet')->input['intent']['notice'])->toBe(['url' => 'https://mini.example/notice', 'command' => null]);
});

it('refuses an unknown host release and a fleet without exactly one canary', function (): void {
    connServerNode('nick-vm', 62, ['canary' => true]);
    expect(fn () => app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet', version: '0.5.0'))
        ->toThrow(ResourceOperationException::class, 'The requested Conn Server host release is not in the release catalog.');

    ConnServerNode::query()->update(['canary' => false]);
    expect(fn () => app(ConnServerRollout::class)->admit(dryRun: true, scope: 'fleet'))->toThrow(ResourceOperationException::class, 'Exactly one Conn Server Node must be the canary.');
});

it('reaches only the canary with scope canary, and keeps the canary-first order with scope fleet', function (): void {
    $nick = connServerNode('nick', 60, ['service' => 'com.nckrtl.t3-headless', 'port' => 3780]);
    $mini = connServerNode('mini', 61, ['service' => 'com.t3tools.t3code.service', 'notify_before_restart' => true]);
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);

    $canary = app(ConnServerRollout::class)->admit(dryRun: false, scope: 'canary');
    $canary->forceFill(['status' => 'succeeded', 'lock_key' => null])->save();

    expect(array_column($canary->input['intent']['nodes'], 'node_id'))->toBe([$vm->id])
        ->and(array_column(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'fleet')->input['intent']['nodes'], 'node_id'))->toBe([$vm->id, $nick->id, $mini->id]);
});

it('reaches only the named Nodes with scope nodes, in the order given, without the canary', function (): void {
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

it('keeps a retained Conn rollout hold from the previous serving release', function (): void {
    $first = connServerNode('canary', 60, ['canary' => true]);
    $second = connServerNode('second', 61, []);
    $rollout = app(ConnServerRollout::class);
    $old = $rollout->admit(true, 'nodes', [$first->id]);
    $old->update(['status' => 'failed', 'lock_key' => 'fleet:conn-server-rollout']);

    expect(fn () => $rollout->admit(true, 'nodes', [$second->id]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe(1);
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

it('records exact host Conn memberships through Tool writes and changes observations only after protected rollout', function (): void {
    $first = connServerNode('first', 61, ['canary' => true]);
    $second = connServerNode('second', 62, []);
    Node::query()->whereKey([$first->id, $second->id])->update(['platform' => 'macos']);
    $first->refresh();
    $tools = app(StartToolOperationAction::class);
    $rollout = app(ConnServerRollout::class);
    expect(fn () => $tools->execute('install', $first->id, 'conn-server', 'conn-server', null, version: '9.9.9'))
        ->toThrow(ToolOperationException::class);
    $install = $tools->execute('install', $first->id, 'conn-server', 'conn-server', null, version: '0.5.1');
    $tools->execute('install', $second->id, 'conn-server', 'conn-server', null, version: '0.5.2');
    $membership = Tool::query()->findOrFail($install->result['tool_id']);
    expect($install->result['execution'])->toBe('membership_only')->and($install->status->value)->toBe('succeeded')
        ->and($membership->installed_version)->toBeNull()->and($membership->desired_version)->toBe('0.5.1')
        ->and(app(ToolConvergeIntent::class)->capture($first))->toBe([]);
    expect(fn () => $rollout->admit(false, 'fleet'))->toThrow(ResourceOperationException::class, 'Selected Nodes must desire the requested release.');
    $run = $rollout->admit(false, 'nodes', [$first->id]);
    expect($run->input['intent']['version'])->toBe('0.5.1')->and($run->input['intent']['nodes'][0]['tool_id'])->toBe($membership->id);
    expect(fn () => $tools->execute('remove', $first->id, 'conn-server', 'conn-server', null, $membership))->toThrow(ResourceOperationException::class);
    $run->update(['state' => ['swamp_run_id' => 'fixture']]);
    app(ConnServerRolloutProjection::class)->apply($run, ['run_id' => 'fixture', 'status' => 'succeeded', 'started_at' => now()->toAtomString(),
        'finished_at' => now()->toAtomString(), 'result' => ['version' => '0.5.1', 'dry_run' => false, 'nodes' => [['node_id' => $first->id, 'outcome' => 'updated', 'observed_version' => '0.5.1']]]]);
    $membership->refresh();
    $tools->execute('update', $first->id, 'conn-server', 'conn-server', null, $membership, version: '0.5.2');
    expect($membership->refresh()->installed_version)->toBe('0.5.1')->and($membership->desired_version)->toBe('0.5.2');
    $tools->execute('remove', $first->id, 'conn-server', 'conn-server', null, $membership);
    expect(Tool::query()->find($membership->id))->toBeNull()
        ->and(ConnServerNode::query()->where('node_id', $first->id)->firstOrFail()->tool_withdrawn)->toBeTrue();
});

it('admits physical withdrawal only for one explicitly named and previously withdrawn Linux host', function (): void {
    connServerNode('canary', 61, ['canary' => true]);
    $linux = connServerNode('linux', 62, ['platform' => 'linux', 'path' => '/opt/conn-server/current', 'service' => 'conn-server']);
    $linux->update(['platform' => 'linux', 'architecture' => 'x86_64']);
    $rollout = app(ConnServerRollout::class);
    expect(fn () => $rollout->admit(true, 'fleet', withdraw: true))->toThrow(ResourceOperationException::class, 'Withdrawal requires one explicit Node');
    expect(fn () => $rollout->admit(true, 'nodes', [$linux->id], withdraw: true))->toThrow(ResourceOperationException::class, 'Service withdrawal requires');
    $tools = app(StartToolOperationAction::class);
    $install = $tools->execute('install', $linux->id, 'conn-server', 'conn-server', null);
    $tools->execute('remove', $linux->id, 'conn-server', 'conn-server', null, Tool::query()->findOrFail($install->result['tool_id']));
    expect(fn () => $rollout->admit(true, 'nodes', [$linux->id]))->toThrow(ResourceOperationException::class, 'not enrolled');
    $run = $rollout->admit(true, 'nodes', [$linux->id], withdraw: true);
    expect($run->input['intent'])->toMatchArray(['withdraw' => true, 'dry_run' => true]);
    expect(array_column($run->input['intent']['nodes'], 'node_id'))->toBe([$linux->id])
        ->and($run->input['intent'])->not->toHaveKeys(['version', 'linux', 'mac']);
});

it('requires an exact active file coordinate for a migrated host without enrolling it', function (): void {
    $node = connServerNode('canary', 61, ['canary' => true]);
    $policy = app(ManageToolPolicyAction::class);
    $path = TestOrbitHome::scratch('conn-tools.yaml');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, json_encode(['schema' => 1, 'packages' => []], JSON_THROW_ON_ERROR));
    $policy->change('file', $path);
    $rollout = app(ConnServerRollout::class);
    expect(fn () => $rollout->admit(false, 'nodes', [$node->id], version: '0.5.2'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tool.not_in_catalog'));
    file_put_contents($path, json_encode(['schema' => 1, 'packages' => [['manager' => 'conn-server', 'package' => 'conn-server', 'version' => '0.5.1']]], JSON_THROW_ON_ERROR));
    expect(fn () => $rollout->admit(false, 'nodes', [$node->id], version: '0.5.2'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.release_conflict'));
    expect(Operation::count())->toBe(0);
    $run = $rollout->admit(false, 'nodes', [$node->id]);
    expect($run->input['intent']['version'])->toBe('0.5.1')->and($run->input['intent']['nodes'][0])->not->toHaveKey('tool_id')
        ->and(Tool::count())->toBe(0);
});

it('keeps identical Conn requests unchanged and replays keyed withdrawal through Tool authorization', function (): void {
    $node = connServerNode('linux', 61, ['canary' => true, 'platform' => 'linux', 'path' => '/opt/conn-server/current', 'service' => 'conn-server']);
    $node->update(['platform' => 'linux', 'architecture' => 'x86_64']);
    $control = SwampNodeFixture::ensure();
    $control->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $control->wireguard_ip]);
    $policy = app(ManageToolPolicyAction::class);
    $before = $policy->show('snapshot')['document'];
    $payload = ['node_id' => $node->id, 'manager' => 'conn-server', 'package' => 'conn-server', 'desired_version' => '0.5.2'];
    $install = $this->postJson('/api/v1/tools', $payload)->assertAccepted()->assertJsonPath('data.result.outcome', 'applied');
    $tool = Tool::query()->findOrFail($install->json('data.result.tool_id'));
    $tool->update(['installed_version' => '0.5.2']);
    $this->postJson('/api/v1/tools', $payload)->assertAccepted()->assertJsonPath('data.result.outcome', 'unchanged')
        ->assertJsonPath('data.result.installed_version', '0.5.2');
    $this->postJson("/api/v1/tools/{$tool->id}/update", ['desired_version' => '0.5.2'])->assertAccepted()->assertJsonPath('data.result.outcome', 'unchanged');
    $this->postJson("/api/v1/tools/{$tool->id}/update", ['desired_version' => '0.5.1'])->assertAccepted()->assertJsonPath('data.result.outcome', 'applied')
        ->assertJsonPath('data.result.installed_version', '0.5.2');
    $remove = $this->withHeader('Idempotency-Key', 'retry-withdrawal')->deleteJson("/api/v1/tools/{$tool->id}")->assertAccepted();
    $this->deleteJson("/api/v1/tools/{$tool->id}")->assertAccepted()->assertJsonPath('data.id', $remove->json('data.id'))
        ->assertJsonPath('data.result.tool_id', $tool->id);
    expect(Tool::count())->toBe(0)->and(Operation::query()->where('kind', 'tool.remove')->count())->toBe(1);
    $after = $policy->show('snapshot')['document'];
    expect($after['memberships'])->toBe($before['memberships'])
        ->and($before['conn_server_rollout'])->toBe([['node_id' => $node->id, 'withdrawn' => false]])
        ->and($after['conn_server_rollout'])->toBe([['node_id' => $node->id, 'withdrawn' => true]]);
    $policy->change('file', null);
    $policy->change('database', null);
    expect($policy->show('snapshot')['document'])->toBe($after);
});

it('checks managed Conn in Doctor against protected health observations, not the selected replacement', function (): void {
    $node = connServerNode('linux', 61, ['canary' => true, 'platform' => 'linux', 'path' => '/opt/conn-server/current', 'service' => 'conn-server']);
    $node->update(['platform' => 'linux', 'architecture' => 'x86_64']);
    $ops = app(StartToolOperationAction::class);
    $member = Tool::query()->findOrFail($ops->execute('install', $node->id, 'conn-server', 'conn-server', null, version: '0.5.2')->result['tool_id']);
    $doctor = fn (): array => app(FactDoctor::class)->report([$node], [DoctorFamily::Tool])->toArray()['nodes'][0]['families'][0];
    expect($doctor())->toMatchArray(['checked' => 1, 'status' => 'unverifiable']);
    $rollout = app(ConnServerRollout::class);
    $complete = static function (Operation $run, array $observation): void {
        $run->update(['state' => ['swamp_run_id' => 'fixture']]);
        app(ConnServerRolloutProjection::class)->apply($run, ['run_id' => 'fixture', 'status' => 'succeeded', 'started_at' => now()->toAtomString(),
            'finished_at' => now()->toAtomString(), 'result' => ['version' => $run->input['intent']['version'], 'dry_run' => $run->input['intent']['dry_run'], 'nodes' => [$observation]]]);
    };
    $complete($rollout->admit(true, 'canary'), ['node_id' => $node->id, 'outcome' => 'checked', 'observed_version' => '0.5.1']);
    expect($member->fresh()->installed_version)->toBe('0.5.1')->and($doctor()['issues'][0])->toMatchArray(['code' => 'tool.version_mismatch', 'expected' => '0.5.2', 'observed' => '0.5.1']);
    $complete($rollout->admit(false, 'canary'), ['node_id' => $node->id, 'outcome' => 'updated', 'observed_version' => '0.5.1']);
    expect($member->fresh()->installed_version)->toBe('0.5.1');
    $complete($rollout->admit(false, 'canary'), ['node_id' => $node->id, 'outcome' => 'updated', 'observed_version' => '0.5.2']);
    expect($member->fresh()->installed_version)->toBe('0.5.2')->and($doctor())->toMatchArray(['checked' => 1, 'status' => 'healthy', 'issues' => []]);
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
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    enrollConnServer($canary);
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $url = '/api/v1/fleet/conn-server/rollout';
    $body = ['scope' => 'canary', 'dry_run' => false];
    $key = ['Idempotency-Key' => 'canary-rollout'];
    $secondKey = ['Idempotency-Key' => 'canary-rollout-also'];

    $response = $this->postJson($url, $body, $key)->assertAccepted();
    $check = Operation::query()->where('kind', ConnServerRollout::Kind)->sole();
    $response->assertJsonPath('data.id', $check->id);
    expect($check->input['intent']['dry_run'])->toBeTrue();
    $this->postJson($url, $body, $secondKey)->assertAccepted()->assertJsonPath('data.id', $check->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);

    $replay = $this->postJson($url, $body, $key)->assertAccepted();
    expect(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe(1);
    $replay->assertJsonPath('data.id', $check->id);
    $this->postJson($url, $body, $secondKey)->assertAccepted()->assertJsonPath('data.id', $check->id);
    $this->postJson($url, ['scope' => 'canary', 'dry_run' => true], $key)
        ->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    expect(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe(1);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    $fresh = $this->postJson($url, $body, ['Idempotency-Key' => 'canary-update'])->assertAccepted();
    $update = Operation::query()->where('kind', ConnServerRollout::Kind)->whereKeyNot($check->id)->sole();
    $fresh->assertJsonPath('data.id', $update->id);
    expect($update->input['intent']['dry_run'])->toBeFalse();
    Queue::assertPushed(StartAdmittedSwampRun::class, 2);
});

it('skips an updated installation in a promotion retry after follower settings change', function (bool $changeSsh): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    $updated = connServerNode('updated', 61, ['notify_before_restart' => true]);
    $failed = connServerNode('failed', 60, []);
    foreach ([$canary, $updated, $failed] as $node) {
        enrollConnServer($node);
    }
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
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    app()->instance(AgentDelivery::class, new AgentDelivery(config()->string('orbit.gateway_checkout').'/missing-manifest.json', '/unused'));

    $pin = app(ConnServerRollout::class)->releasePin();

    expect($pin?->version)->toBe('0.5.2')
        ->and($pin?->releaseCanary)->toBeTrue();
});

it('records a follower SSH blocker without invalidating the verified canary', function (): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);
    $other = connServerNode('mini', 61, []);
    enrollConnServer($vm);
    enrollConnServer($other);
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
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $vm = connServerNode('nick-vm', 62, ['canary' => true]);
    $mini = connServerNode('mini', 61, ['notify_before_restart' => true]);
    $nick = connServerNode('nick', 60, []);
    foreach ([$vm, $mini, $nick] as $node) {
        enrollConnServer($node);
    }
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

    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
        $pin['linux_x64']['sha256'] = str_repeat('c', 64);
    });
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $retry->id))->toThrow(ResourceOperationException::class, 'successful canary_operation_id')
        ->and($rollout->releaseGate($rollout->releasePin())->id)->not->toBe($check->id);
});

it('promotes only matching enrolled Nodes and rechecks when the promotion targets change', function (): void {
    $catalog = File::get(resource_path('machine-software/conn-server-releases.yaml'));
    serveConnServerCatalog($catalog);
    $current = Yaml::parse($catalog)['default'];
    $olderPin = '0.5.2';
    $canary = connServerNode('canary', 60, ['canary' => true]);
    $matching = connServerNode('matching', 61, []);
    $older = connServerNode('older', 62, []);
    $withdrawn = connServerNode('withdrawn', 63, []);
    $unselected = connServerNode('unselected', 64, []);
    $canaryTool = enrollConnServer($canary, $olderPin);
    $olderTool = enrollConnServer($older, $olderPin);
    $matchingTool = enrollConnServer($matching, $current);
    $removed = enrollConnServer($withdrawn, $current);
    $tools = app(StartToolOperationAction::class);
    $tools->execute('remove', $withdrawn->id, 'conn-server', 'conn-server', null, $removed);
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();

    expect($pin->releaseCanary)->toBeTrue()
        ->and($rollout->releaseGate($pin))->toBeNull()
        ->and(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe(0);
    $tools->execute('update', $canary->id, 'conn-server', 'conn-server', null, $canaryTool, version: $current);
    $check = $rollout->releaseGate($pin);
    expect($check->input['intent']['version'])->toBe($current)
        ->and(array_column($check->input['intent']['nodes'], 'node_id'))->toBe([$canary->id]);
    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    $probe->update(['state' => ['swamp_run_id' => 'fixture']]);
    app(ConnServerRolloutProjection::class)->apply($probe, ['run_id' => 'fixture', 'status' => 'succeeded', 'started_at' => now()->toAtomString(),
        'finished_at' => now()->toAtomString(), 'result' => ['version' => $current, 'dry_run' => false,
            'nodes' => [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true, 'observed_version' => $current]]]]);
    expect($rollout->releaseGate($pin))->toBeNull()->and($canaryTool->fresh()->installed_version)->toBe($current);
    $preflight = $rollout->admit(true, 'fleet');
    expect(array_column($preflight->input['intent']['nodes'], 'node_id'))->toBe([$matching->id]);
    finishConnRollout($preflight, 'succeeded', [['node_id' => $matching->id, 'outcome' => 'checked']]);
    expect(fn () => $rollout->admit(false, 'nodes', [$matching->id], version: $current))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.canary_required'));
    $promotion = $rollout->admit(false, 'fleet', canaryOperationId: $probe->id);
    expect(array_column($promotion->input['intent']['nodes'], 'node_id'))->toBe([$matching->id])
        ->and(array_column($promotion->input['intent']['nodes'], 'tool_id'))->toBe([$matchingTool->id])
        ->and($olderTool->fresh()->desired_version)->toBe($olderPin);
    finishConnRollout($promotion, 'succeeded', [['node_id' => $matching->id, 'outcome' => 'updated']]);

    enrollConnServer($unselected, $current);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.preflight_required'));
    $recheck = $rollout->admit(true, 'fleet', retryOperationId: $preflight->id);
    expect(array_column($recheck->input['intent']['nodes'], 'node_id'))->toBe([$matching->id, $unselected->id]);
    finishConnRollout($recheck, 'succeeded', [['node_id' => $matching->id, 'outcome' => 'checked'], ['node_id' => $unselected->id, 'outcome' => 'checked']]);
    $continued = $rollout->admit(false, 'fleet', canaryOperationId: $probe->id);
    expect(array_column($continued->input['intent']['nodes'], 'node_id'))->toBe([$unselected->id]);
    finishConnRollout($continued, 'succeeded', [['node_id' => $unselected->id, 'outcome' => 'updated']]);

    $tools->execute('update', $canary->id, 'conn-server', 'conn-server', null, $canaryTool->refresh(), version: $olderPin);
    $downgrade = $rollout->admit(false, 'nodes', [$canary->id]);
    $downgrade->update(['state' => ['swamp_run_id' => 'fixture-downgrade']]);
    app(ConnServerRolloutProjection::class)->apply($downgrade, ['run_id' => 'fixture-downgrade', 'status' => 'succeeded', 'started_at' => now()->toAtomString(),
        'finished_at' => now()->toAtomString(), 'result' => ['version' => $olderPin, 'dry_run' => false,
            'nodes' => [['node_id' => $canary->id, 'outcome' => 'updated', 'observed_version' => $olderPin]]]]);
    $tools->execute('update', $canary->id, 'conn-server', 'conn-server', null, $canaryTool->refresh(), version: $current);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.canary_required'));
    $retry = $rollout->admit(false, 'canary', retryOperationId: $probe->id);
    expect($retry->input['intent']['probe_thread'])->toBeTrue()->and($retry->id)->not->toBe($probe->id);
});

it('uses the edited file coordinate without enrolling the legacy service or bypassing review', function (): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $canary = connServerNode('canary', 60, ['canary' => true]);
    $legacy = connServerNode('legacy', 61, []);
    $path = TestOrbitHome::scratch('reviewed-conn-tools.yaml');
    File::ensureDirectoryExists(dirname($path));
    $file = static fn (string $version): int|false => file_put_contents($path, Yaml::dump(['schema' => 1, 'packages' => [
        ['manager' => 'conn-server', 'package' => 'conn-server', 'version' => $version],
    ]], 4));
    $file('0.5.1');
    app(ManageToolPolicyAction::class)->change('file', $path);
    app(StartToolOperationAction::class)->execute('install', $canary->id, 'conn-server', 'conn-server', null);
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    expect($rollout->releaseGate($pin))->toBeNull();
    expect(fn () => $rollout->admit(false, 'canary', version: '0.5.2'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.release_conflict'));
    $file('0.5.2');
    expect(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe(0);
    $check = $rollout->admit(true, 'canary');
    expect($check->input['release_stage'])->toBe('check')->and($rollout->releaseGate($pin)->id)->toBe($check->id);
    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $probe = $rollout->admit(false, 'canary');
    expect($probe->input['intent']['probe_thread'])->toBeTrue();
    finishConnRollout($probe, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true]]);
    expect($rollout->releaseGate($pin))->toBeNull()->and(Operation::query()->where('input->release_stage', 'preflight')->count())->toBe(0)
        ->and(Tool::query()->where('node_id', $legacy->id)->exists())->toBeFalse();
});

it('requires a canary apply newer than a failed later check before the canary or promotion advances', function (): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    $follower = connServerNode('follower', 61, []);
    enrollConnServer($canary);
    enrollConnServer($follower);
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    $check = $rollout->releaseGate($pin);
    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    finishConnRollout($probe, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true]]);
    expect($rollout->releaseGate($pin))->toBeNull();
    $preflight = Operation::query()->where('input->release_stage', 'preflight')->sole();
    finishConnRollout($preflight, 'succeeded', [['node_id' => $follower->id, 'outcome' => 'checked']]);

    $recheck = $rollout->admit(true, 'canary', retryOperationId: $check->id);
    finishConnRollout($recheck, 'failed', [['node_id' => $canary->id, 'outcome' => 'failed', 'reason' => 'conn_server.health_unreachable']]);

    expect($rollout->admit(false, 'canary')->id)->toBe($recheck->id)
        ->and($rollout->releaseGate($pin)->id)->toBe($recheck->id);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.canary_required'));
    expect(Operation::query()->where('input->release_stage', 'promotion')->exists())->toBeFalse();

    $passed = $rollout->admit(true, 'canary', retryOperationId: $recheck->id);
    finishConnRollout($passed, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.canary_required'));
    $fresh = $rollout->admit(false, 'canary');
    expect($fresh->id)->toBeGreaterThan($passed->id)->and($fresh->input['intent']['dry_run'])->toBeFalse()
        ->and($rollout->admit(false, 'canary')->id)->toBe($fresh->id);
    finishConnRollout($fresh, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true]]);
    expect($rollout->admit(false, 'fleet', canaryOperationId: $fresh->id)->input['release_stage'])->toBe('promotion');
});

it('leaves Conn alone in the automatic release pass without a designated canary, but explicit runs still require one', function (): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    enrollConnServer(connServerNode('follower', 61, []));
    $rollout = app(ConnServerRollout::class);

    expect($rollout->releaseGate($rollout->releasePin()))->toBeNull()
        ->and(Operation::query()->where('kind', ConnServerRollout::Kind)->exists())->toBeFalse();
    expect(fn () => $rollout->admit(true, 'fleet'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.canary_unset'));
});

it('stops canary and promotion advancement on a later failed canary observation from any scope', function (string $scope): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    $follower = connServerNode('follower', 61, []);
    enrollConnServer($canary);
    if ($scope === 'nodes') {
        enrollConnServer($follower);
    }
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    $check = $rollout->releaseGate($pin);
    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    finishConnRollout($probe, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true]]);
    expect($rollout->releaseGate($pin))->toBeNull();
    if ($scope === 'nodes') {
        finishConnRollout(Operation::query()->where('input->release_stage', 'preflight')->sole(), 'succeeded', [['node_id' => $follower->id, 'outcome' => 'checked']]);
    }

    $observed = $scope === 'nodes' ? $rollout->admit(true, 'nodes', [$canary->id]) : $rollout->admit(true, 'fleet');
    expect(array_column($observed->input['intent']['nodes'], 'node_id'))->toBe([$canary->id]);
    finishConnRollout($observed, 'failed', [['node_id' => $canary->id, 'outcome' => 'failed', 'reason' => 'conn_server.health_unreachable']]);

    expect($rollout->releaseGate($pin)->id)->toBe($observed->id)
        ->and($rollout->admit(false, 'canary')->id)->toBe($observed->id);
    if ($scope === 'nodes') {
        expect(fn () => $rollout->admit(false, 'fleet', canaryOperationId: $probe->id))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn_server.canary_required'));
    }
    expect(Operation::query()->where('input->release_stage', 'promotion')->exists())->toBeFalse();

    $recheck = $rollout->admit(true, 'canary', retryOperationId: $observed->id);
    expect($recheck->input)->toMatchArray(['release_stage' => 'check', 'retry_operation_id' => $observed->id]);
    finishConnRollout($recheck, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $fresh = $rollout->releaseGate($pin);
    expect($fresh->id)->toBeGreaterThan($recheck->id)->and($fresh->input['release_stage'])->toBe('canary');
})->with(['scope nodes' => ['nodes'], 'fleet dry run of the canary alone' => ['fleet']]);

it('returns the existing retry for a repeated canary retry request instead of starting another stage', function (): void {
    connServerPin(static function (array &$pin): void {
        $pin['release_canary'] = true;
    });
    $canary = connServerNode('canary', 62, ['canary' => true]);
    enrollConnServer($canary);
    $rollout = app(ConnServerRollout::class);
    $pin = $rollout->releasePin();
    $check = $rollout->releaseGate($pin);
    finishConnRollout($check, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $probe = $rollout->releaseGate($pin);
    finishConnRollout($probe, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'updated', 'thread_read' => true]]);
    $failedCheck = $rollout->admit(true, 'canary', retryOperationId: $check->id);
    finishConnRollout($failedCheck, 'failed', [['node_id' => $canary->id, 'outcome' => 'failed']]);
    $gateway = SwampNodeFixture::ensure();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $url = '/api/v1/fleet/conn-server/rollout';
    $body = ['scope' => 'canary', 'dry_run' => false, 'retry_operation_id' => $failedCheck->id];

    $first = $this->postJson($url, $body, ['Idempotency-Key' => 'retry-one'])->assertAccepted()->json('data.id');
    $retry = Operation::query()->findOrFail($first);
    expect($retry->input)->toMatchArray(['release_stage' => 'check', 'retry_operation_id' => $failedCheck->id])
        ->and($retry->input['intent']['dry_run'])->toBeTrue();
    finishConnRollout($retry, 'succeeded', [['node_id' => $canary->id, 'outcome' => 'checked']]);
    $count = Operation::query()->where('kind', ConnServerRollout::Kind)->count();

    $this->postJson($url, $body, ['Idempotency-Key' => 'retry-two'])->assertAccepted()->assertJsonPath('data.id', $retry->id);
    expect(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe($count);
    $this->postJson($url, ['scope' => 'canary', 'dry_run' => false, 'retry_operation_id' => $probe->id], ['Idempotency-Key' => 'retry-stale'])
        ->assertConflict()->assertJsonPath('error.code', 'conn_server.retry_stale');
    $update = $this->postJson($url, ['scope' => 'canary', 'dry_run' => false], ['Idempotency-Key' => 'update'])->assertAccepted()->json('data.id');
    expect(Operation::query()->findOrFail($update)->input['release_stage'])->toBe('canary')
        ->and(Operation::query()->where('kind', ConnServerRollout::Kind)->count())->toBe($count + 1);
});
