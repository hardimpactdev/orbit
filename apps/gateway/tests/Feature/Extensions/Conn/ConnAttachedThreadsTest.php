<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

uses(LazilyRefreshDatabase::class);

function attached_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

function attached_server(string $environmentId, Node $node, ?Instance $instance = null): ConnEnvironment
{
    $server = ConnEnvironment::query()->create([
        'environment_id' => $environmentId, 'label' => $environmentId, 'url' => 'http://10.44.0.30:3773', 'node_id' => $node->id,
        'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30), 'registered_at' => Carbon::now(),
    ]);

    if ($instance instanceof Instance) {
        $server->forceFill(['instance_id' => $instance->id])->save();
    }

    return $server;
}

/** @param array<string, mixed> $body */
function attached_call(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/conn/'.$path, $body);
}

/**
 * @param  list<array{0: string, 1: string}>  $threads
 * @return array{schema: int, threads: list<array{environmentId: string, threadId: string}>}
 */
function attached_value(array $threads): array
{
    return ['schema' => 1, 'threads' => array_map(static fn (array $thread): array => ['environmentId' => $thread[0], 'threadId' => $thread[1]], $threads)];
}

/** @param list<array{0: string, 1: string}> $threads */
function attached_patch(Node $caller, int $version, array $threads): TestResponse
{
    return attached_call($caller, 'PATCH', 'profiles/'.test()->profile->id.'/settings', [
        'sections' => ['attachedThreads' => ['version' => $version, 'value' => attached_value($threads)]],
    ]);
}

/** @return array{version: int, threads: list<array{0: string, 1: string}>} */
function attached_read(Node $caller): array
{
    $data = attached_call($caller, 'GET', 'profiles/'.test()->profile->id.'/settings')->assertOk()->json('data');

    return [
        'version' => $data['sections']['attachedThreads']['version'] ?? 0,
        'threads' => array_map(static fn (array $thread): array => [$thread['environmentId'], $thread['threadId']], $data['settings']['attachedThreads']['threads'] ?? []),
    ];
}

/**
 * The servers a device connects to: the attached environments that `GET /environments` lists for it.
 *
 * @return list<string>
 */
function attached_servers(Node $caller): array
{
    $visible = array_column(attached_call($caller, 'GET', 'environments')->assertOk()->json('data'), 'environment_id');
    $attached = array_unique(array_column(attached_read($caller)['threads'], 0));

    return array_values(array_intersect($visible, $attached));
}

/**
 * The three-way merge of a set that a client runs after a conflict: the remote set with the local adds and removes.
 *
 * @param  list<array{0: string, 1: string}>  $base
 * @param  list<array{0: string, 1: string}>  $local
 * @param  list<array{0: string, 1: string}>  $remote
 * @return list<array{0: string, 1: string}>
 */
function attached_merge(array $base, array $local, array $remote): array
{
    $key = static fn (array $thread): string => $thread[0].':'.$thread[1];
    $baseKeys = array_map($key, $base);
    $localKeys = array_map($key, $local);
    $merged = array_filter($remote, static fn (array $thread): bool => ! in_array($key($thread), array_diff($baseKeys, $localKeys), true));

    foreach ($local as $thread) {
        if (! in_array($key($thread), $baseKeys, true) && ! in_array($key($thread), array_map($key, $merged), true)) {
            $merged[] = $thread;
        }
    }

    return array_values($merged);
}

beforeEach(function (): void {
    app(ExtensionStore::class)->set('conn', true);
    $this->beast = attached_node('beast', '10.44.0.7');
    $this->mini = attached_node('mini', '10.44.0.9');
    $this->phone = attached_node('phone', '10.44.0.77');
    $this->desktop = attached_node('desktop', '10.44.0.78');
    $this->phone->accessibleNodes()->attach($this->beast->id);
    $this->desktop->accessibleNodes()->attach([$this->beast->id, $this->mini->id]);
    $this->profile = ConnProfile::query()->create(['name' => 'conn-lane-test', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
    ConnProfileBinding::query()->create(['node_id' => $this->phone->id, 'conn_profile_id' => $this->profile->id]);
    ConnProfileBinding::query()->create(['node_id' => $this->desktop->id, 'conn_profile_id' => $this->profile->id]);
    $project = Project::query()->create(['name' => 'Mealou', 'slug' => 'mealou', 'repository_url' => 'https://github.com/nckrtl/mealou.git', 'default_branch' => 'main', 'root' => '.']);
    $this->onBeast = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'feature', 'checkout_path' => '/srv/workspace/feature']);
    attached_server('env-beast', $this->beast, $this->onBeast);
    attached_server('env-mini', $this->mini);
});

it('shows an attach on one device in the next read of another device on the profile', function (): void {
    $before = attached_call($this->desktop, 'GET', 'me')->assertOk()->json('data.profile.settings_version');

    attached_patch($this->phone, 0, [['env-beast', 'thread-1']])->assertOk()
        ->assertJsonPath('data.sections.attachedThreads.version', 1)
        ->assertJsonPath('data.sections.attachedThreads.updated_by', 'phone');

    expect(attached_call($this->desktop, 'GET', 'me')->json('data.profile.settings_version'))->toBe($before + 1)
        ->and(attached_read($this->desktop))->toBe(['version' => 1, 'threads' => [['env-beast', 'thread-1']]]);
});

it('connects each device to the attached servers it may see and drops a server with its last detached thread', function (): void {
    attached_patch($this->desktop, 0, [['env-beast', 'thread-1'], ['env-mini', 'thread-2'], ['env-mini', 'thread-3']])->assertOk();

    expect(attached_servers($this->desktop))->toBe(['env-beast', 'env-mini'])
        ->and(attached_servers($this->phone))->toBe(['env-beast']);

    attached_patch($this->phone, 1, [['env-beast', 'thread-1'], ['env-mini', 'thread-3']])->assertOk();
    expect(attached_servers($this->desktop))->toBe(['env-beast', 'env-mini']);

    attached_patch($this->phone, 2, [['env-beast', 'thread-1']])->assertOk();
    expect(attached_servers($this->desktop))->toBe(['env-beast']);
});

it('lands an add on one device and a remove on another made at the same time', function (): void {
    attached_patch($this->desktop, 0, [['env-beast', 'thread-1'], ['env-mini', 'thread-2']])->assertOk();
    $phoneBase = attached_read($this->phone);
    $desktopBase = attached_read($this->desktop);

    attached_patch($this->desktop, $desktopBase['version'], [...$desktopBase['threads'], ['env-mini', 'thread-3']])->assertOk();
    $phoneLocal = [['env-mini', 'thread-2']];
    attached_patch($this->phone, $phoneBase['version'], $phoneLocal)->assertStatus(409)
        ->assertJsonPath('error.code', 'conn.settings_version_conflict')
        ->assertJsonPath('error.details.sections.attachedThreads', 2);

    $remote = attached_read($this->phone);
    attached_patch($this->phone, $remote['version'], attached_merge($phoneBase['threads'], $phoneLocal, $remote['threads']))->assertOk();

    expect(attached_read($this->desktop)['threads'])->toBe([['env-mini', 'thread-2'], ['env-mini', 'thread-3']]);
});

it('lets a device carry an attached environment it cannot see and refuses one it adds', function (): void {
    attached_patch($this->desktop, 0, [['env-mini', 'thread-2']])->assertOk();

    attached_patch($this->phone, 1, [['env-mini', 'thread-2'], ['env-beast', 'thread-1']])->assertOk();
    attached_patch($this->phone, 2, [['env-beast', 'thread-1']])->assertOk();
    $refused = attached_patch($this->phone, 3, [['env-beast', 'thread-1'], ['env-mini', 'thread-2']])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');
    expect($refused->json('error.details'))->toBe(['sections.attachedThreads.value.threads.1.environmentId' => ['The environment env-mini is not a Conn environment you may see.']]);
    attached_patch($this->desktop, 3, [['env-gone', 'thread-9']])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    expect(attached_read($this->phone))->toBe(['version' => 3, 'threads' => [['env-beast', 'thread-1']]]);
});

it('drops a destroyed Instance\'s threads with a new section version written by Orbit', function (): void {
    attached_patch($this->desktop, 0, [['env-beast', 'thread-1'], ['env-mini', 'thread-2']])->assertOk();
    $other = ConnProfile::query()->create(['name' => 'other', 'settings' => ['workspaces' => []], 'settings_version' => 1]);

    DB::transaction(fn () => app(ConnInstanceLifecycle::class)->projectDestroy($this->onBeast));

    $data = attached_call($this->phone, 'GET', 'profiles/'.$this->profile->id.'/settings')->assertOk()->json('data');
    expect($data['settings']['attachedThreads']['threads'])->toBe([['environmentId' => 'env-mini', 'threadId' => 'thread-2']])
        ->and($data['sections']['attachedThreads']['version'])->toBe(2)
        ->and($data['sections']['attachedThreads']['updated_by'])->toBeNull()
        ->and($data['version'])->toBe(3)
        ->and($other->fresh()?->settings_version)->toBe(1)
        ->and(attached_servers($this->desktop))->toBe(['env-mini']);
    attached_patch($this->desktop, 1, [['env-beast', 'thread-1'], ['env-mini', 'thread-2'], ['env-mini', 'thread-3']])->assertStatus(409);
});

it('leaves out the threads of an environment that is gone on read and on the next write, without a new version', function (): void {
    attached_patch($this->desktop, 0, [['env-beast', 'thread-1'], ['env-mini', 'thread-2']])->assertOk();
    ConnEnvironment::query()->where('environment_id', 'env-mini')->delete();

    expect(attached_read($this->desktop))->toBe(['version' => 1, 'threads' => [['env-beast', 'thread-1']]])
        ->and($this->profile->fresh()?->settings_version)->toBe(2);

    attached_patch($this->phone, 1, [['env-beast', 'thread-1'], ['env-mini', 'thread-2'], ['env-beast', 'thread-4']])->assertOk();
    expect($this->profile->fresh()?->settings[ConnProfileSections::AttachedThreads]['threads'])->toBe([
        ['environmentId' => 'env-beast', 'threadId' => 'thread-1'], ['environmentId' => 'env-beast', 'threadId' => 'thread-4'],
    ]);
});

it('keeps the attached set through a workspaces replace and out of a profile that never attached', function (): void {
    $fresh = attached_call($this->phone, 'GET', 'profiles/'.$this->profile->id.'/settings')->assertOk();
    expect($fresh->json('data.settings'))->toBe(['workspaces' => []])
        ->and($fresh->json('data.sections'))->toBe([]);

    attached_patch($this->desktop, 0, [['env-beast', 'thread-1']])->assertOk();
    $workspace = ['id' => 'ws-1', 'name' => 'Orbit', 'color' => 'blue', 'icon' => null];
    attached_call($this->phone, 'PUT', 'profiles/'.$this->profile->id.'/settings', ['version' => 2, 'settings' => ['workspaces' => [$workspace]]])
        ->assertOk()->assertJsonPath('data.settings.workspaces.0.id', 'ws-1')
        ->assertJsonPath('data.settings.attachedThreads.threads.0.threadId', 'thread-1')
        ->assertJsonPath('data.sections.attachedThreads.version', 1);
    attached_call($this->phone, 'PUT', 'profiles/'.$this->profile->id.'/settings', ['version' => 3, 'settings' => ['attachedThreads' => attached_value([])]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
});

it('keeps an empty set as a list in an object and removes the section with null', function (): void {
    attached_patch($this->desktop, 0, [])->assertOk();
    $raw = attached_call($this->phone, 'GET', 'profiles/'.$this->profile->id.'/settings')->assertOk()->getContent();
    expect($raw)->toContain('"attachedThreads":{"schema":1,"threads":[]}');

    attached_call($this->desktop, 'PATCH', 'profiles/'.$this->profile->id.'/settings', ['sections' => ['attachedThreads' => ['version' => 1, 'value' => null]]])
        ->assertOk()->assertJsonPath('data.sections.attachedThreads.version', 2);
    expect($this->profile->fresh()?->settings)->toBe(['workspaces' => []]);
});

it('refuses an attached set that breaks a rule', function (array $value): void {
    attached_call($this->desktop, 'PATCH', 'profiles/'.$this->profile->id.'/settings', ['sections' => ['attachedThreads' => ['version' => 0, 'value' => $value]]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    expect($this->profile->fresh()?->settings_version)->toBe(1);
})->with([
    'a bare list' => [[['environmentId' => 'env-beast', 'threadId' => 'thread-1']]],
    'no schema' => [['threads' => []]],
    'no threads' => [['schema' => 1]],
    'an unknown key' => [['schema' => 1, 'threads' => [], 'openedAt' => '2026-11-14']],
    'an entry with an unknown key' => [['schema' => 1, 'threads' => [['environmentId' => 'env-beast', 'threadId' => 't', 'title' => 'x']]]],
    'a colon in the environment id' => [attached_value([['env:beast', 'thread-1']])],
    'a thread twice' => [attached_value([['env-beast', 'thread-1'], ['env-beast', 'thread-1']])],
    'more entries than the bound' => [attached_value(array_map(static fn (int $i): array => ['env-beast', "thread-{$i}"], range(1, ConnProfileSections::MaxAttachedThreads + 1)))],
    'more bytes than the bound' => [attached_value(array_map(static fn (int $i): array => ['env-beast', str_pad((string) $i, 200, 'x')], range(1, 400)))],
]);

it('records only the section name and version of an attached-set write in Activity', function (): void {
    attached_patch($this->desktop, 0, [['env-beast', 'thread-1']])->assertOk();

    $activity = Activity::query()->where('command', 'conn:profile:settings:patch')->latest('id')->firstOrFail();
    expect($activity->properties['input'])->toBe(['sections' => ['attachedThreads' => 0]]);
});
