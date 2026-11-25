<?php

declare(strict_types=1);

use App\Actions\Tools\ManageToolPolicyAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\ConnServer\ConnServerRolloutProjection;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolOperationException;
use App\Http\Requests\Fleet\AdmitConnServerRolloutRequest;
use App\Models\ConnServerNode;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TestOrbitHome;

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

beforeEach(function (): void {
    Queue::fake();
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    SwampNodeFixture::ensure();
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
            'sha256' => '40d753aaa246e3137e4ed5cd5caa08586b2dc3eb33f281e5be144b0d3083bb9d', 'bundle_id' => 'com.nckrtl.conn-server', 'team_id' => '9SVJ4SYB9B'])
        ->and($intent['linux'])->toBe(['url' => 'https://github.com/nckrtl/conn/releases/download/conn-server-v0.5.2/conn-server-0.5.2-linux-x64.tar.gz',
            'sha256' => '554766fc35583e2a2284a8c08581ce47dd729efef30315738eebd8f8ad35f539'])
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
    expect(array_column($canary->input['intent']['nodes'], 'node_id'))->toBe([$vm->id]);
    $canary->update(['status' => 'succeeded', 'lock_key' => null]);
    expect(array_column(app(ConnServerRollout::class)->admit(dryRun: false, scope: 'fleet')->input['intent']['nodes'], 'node_id'))->toBe([$vm->id, $nick->id, $mini->id]);
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
    expect(fn () => $rollout->admit(false, 'fleet'))->toThrow(ResourceOperationException::class, 'Selected Nodes must desire the same release.');
    $run = $rollout->admit(false, 'nodes', [$first->id]);
    expect($run->input['intent']['version'])->toBe('0.5.1')->and($run->input['intent']['nodes'][0]['tool_id'])->toBe($membership->id);
    expect(fn () => $tools->execute('remove', $first->id, 'conn-server', 'conn-server', null, $membership))->toThrow(ResourceOperationException::class);
    $run->update(['state' => ['swamp_run_id' => 'fixture']]);
    app(ConnServerRolloutProjection::class)->apply($run, ['run_id' => 'fixture', 'status' => 'succeeded', 'started_at' => now()->toAtomString(),
        'finished_at' => now()->toAtomString(), 'result' => ['version' => '0.5.1', 'dry_run' => false, 'nodes' => [['node_id' => $first->id, 'outcome' => 'updated']]]]);
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
    $complete($rollout->admit(false, 'canary'), ['node_id' => $node->id, 'outcome' => 'updated']);
    expect($member->fresh()->installed_version)->toBe('0.5.2')->and($doctor())->toMatchArray(['checked' => 1, 'status' => 'healthy', 'issues' => []]);
});
