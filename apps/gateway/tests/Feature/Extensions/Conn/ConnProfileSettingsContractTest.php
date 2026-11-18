<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Http\Mcp\ToolManifest;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;
use Orbit\Extensions\Conn\Http\Requests\ConnProfileSettingsRules;
use Orbit\Extensions\Conn\Http\Requests\ReplaceConnProfileSettingsRequest;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Orbit\Extensions\Conn\Models\ConnThread;

uses(LazilyRefreshDatabase::class);

/**
 * `bin/docs-openapi` writes the settings patch body by hand, because the rules depend on the sections a request holds.
 * This keeps the published body, and the MCP tool built from it, on the request rules. It reads the generated
 * `docs/openapi.json` and MCP manifest, so run `bin/build-api` first, as CI does.
 */
function contract_pattern(array $rules): string
{
    $regex = array_values(array_filter($rules, static fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'regex:')));
    expect($regex)->toHaveCount(1)->and($regex[0])->toStartWith('regex:/\A')->toEndWith('\z/');

    return '^'.substr($regex[0], strlen('regex:/\A'), -strlen('\z/')).'$(?![\s\S])';
}

/** @return array<string, array<string, mixed>> */
function contract_sections(): array
{
    $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $tool = collect(ToolManifest::default()->definitions())->firstWhere('name', 'conn-profile-settings-patch');
    expect($tool)->not->toBeNull();

    return [
        'OpenAPI' => $spec['paths']['/api/v1/conn/profiles/{profile}/settings']['patch']['requestBody']['content']['application/json']['schema']['properties']['sections'],
        'MCP tool' => json_decode((string) json_encode($tool->inputSchema['properties']['sections']), true),
    ];
}

it('publishes every patch section with the thread-set rules of the request', function (string $section): void {
    $rules = ConnProfileSettingsRules::forSection($section, 'v', [], nullable: true);

    foreach (contract_sections() as $source => $sections) {
        expect(array_keys($sections['properties']))->toEqualCanonicalizing(ConnProfileSections::Named, $source)
            ->and(array_keys($sections['patternProperties']))->toBe(['^devices\.[a-z][a-z0-9-]{1,23}$'], $source);

        [$value, $null] = $sections['properties'][$section]['properties']['value']['oneOf'];
        $threads = $value['properties']['threads'];
        $entry = $threads['items'];

        expect($null)->toBe(['type' => 'null'], $source)
            ->and($value['additionalProperties'])->toBeFalse()
            ->and($value['required'])->toBe(['schema', 'threads'])
            ->and($value['properties']['schema'])->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 1000])
            ->and($value['description'])->toContain('at most '.(ConnProfileSections::MaxAttachedThreadsBytes / 1024).' KB')
            ->and($threads['maxItems'])->toBe(ConnProfileSections::MaxAttachedThreads)
            ->and($entry['additionalProperties'])->toBeFalse()
            ->and($entry['required'])->toBe(['environmentId', 'threadId'])
            ->and($entry['properties']['environmentId']['pattern'])->toBe(contract_pattern($rules['v.threads.*.environmentId']), $source)
            ->and($entry['properties']['threadId']['pattern'])->toBe(contract_pattern($rules['v.threads.*.threadId']), $source);
    }
})->with([ConnProfileSections::AttachedThreads, ConnProfileSections::SidebarHiddenThreads]);

it('names the attached set in the settings show and patch descriptions', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $path = $spec['paths']['/api/v1/conn/profiles/{profile}/settings'];

    expect($path['get']['description'])->toContain('`attachedThreads`')
        ->and($path['patch']['description'])->toContain('A section is `workspaces`, `appearance`, `attachedThreads`, `sidebarHiddenThreads`, or `devices.<type>`')
        ->toContain('A null value removes any section but `workspaces` and `sidebarHiddenThreads`');
});

it('publishes the replace body with exactly the sections a replace accepts', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $put = $spec['paths']['/api/v1/conn/profiles/{profile}/settings']['put'];
    $settings = $put['requestBody']['content']['application/json']['schema']['properties']['settings'];
    $rule = array_values(array_filter((new ReplaceConnProfileSettingsRequest)->rules()['settings'], static fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'array:')));

    expect($rule)->toHaveCount(1)
        ->and(array_keys($settings['properties']))->toBe(explode(',', substr($rule[0], strlen('array:'))))
        ->and($settings['additionalProperties'])->toBeFalse()
        ->and($put['description'])->toContain('A body that holds any other section, such as `attachedThreads` or `sidebarHiddenThreads`, returns `validation.failed` (422)');
});

function hidden_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

/** @param array<string, mixed> $body */
function hidden_call(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/conn/'.$path, $body);
}

/**
 * @param  list<array{0: string, 1: string}>  $threads
 * @return array{schema: int, threads: list<array{environmentId: string, threadId: string}>}
 */
function hidden_value(array $threads): array
{
    return ['schema' => 1, 'threads' => array_map(static fn (array $thread): array => ['environmentId' => $thread[0], 'threadId' => $thread[1]], $threads)];
}

/** @param array<string, array{version: int, value: mixed}> $sections */
function hidden_patch_sections(Node $caller, array $sections, ?ConnProfile $profile = null): TestResponse
{
    return hidden_call($caller, 'PATCH', 'profiles/'.($profile ?? test()->profile)->id.'/settings', ['sections' => $sections]);
}

function hidden_patch(Node $caller, int $version, mixed $value, ?ConnProfile $profile = null): TestResponse
{
    return hidden_patch_sections($caller, ['sidebarHiddenThreads' => ['version' => $version, 'value' => $value]], $profile);
}

/** @return array<string, mixed> */
function hidden_read(Node $caller, ?ConnProfile $profile = null): array
{
    return hidden_call($caller, 'GET', 'profiles/'.($profile ?? test()->profile)->id.'/settings')->assertOk()->json('data');
}

describe('sidebarHiddenThreads', function (): void {
    beforeEach(function (): void {
        app(ExtensionStore::class)->set('conn', true);
        $this->beast = hidden_node('beast', '10.44.0.7');
        $this->mini = hidden_node('mini', '10.44.0.9');
        $this->phone = hidden_node('phone', '10.44.0.77');
        $this->desktop = hidden_node('desktop', '10.44.0.78');
        $this->phone->accessibleNodes()->attach($this->beast->id);
        $this->desktop->accessibleNodes()->attach([$this->beast->id, $this->mini->id]);
        $this->profile = ConnProfile::query()->create(['name' => 'conn-hidden-test', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
        ConnProfileBinding::query()->create(['node_id' => $this->phone->id, 'conn_profile_id' => $this->profile->id]);
        ConnProfileBinding::query()->create(['node_id' => $this->desktop->id, 'conn_profile_id' => $this->profile->id]);
        $project = Project::query()->create(['name' => 'Mealou', 'slug' => 'mealou', 'repository_url' => 'https://github.com/nckrtl/mealou.git', 'default_branch' => 'main', 'root' => '.']);
        $this->onBeast = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'feature', 'checkout_path' => '/srv/workspace/feature']);

        foreach (['env-beast' => $this->beast, 'env-mini' => $this->mini] as $environmentId => $node) {
            ConnEnvironment::query()->create([
                'environment_id' => $environmentId, 'label' => $environmentId, 'url' => 'http://10.44.0.30:3773', 'node_id' => $node->id,
                'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30), 'registered_at' => Carbon::now(),
            ])->forceFill(['instance_id' => $environmentId === 'env-beast' ? $this->onBeast->id : null])->save();
        }
    });

    it('reads as version 0 without a value, and shows a first patch in a fresh read on another device', function (): void {
        $fresh = hidden_read($this->desktop);
        expect($fresh['settings'])->not->toHaveKey('sidebarHiddenThreads')
            ->and($fresh['sections'])->not->toHaveKey('sidebarHiddenThreads');

        $value = hidden_value([['env-beast', 'thread-1'], ['env-mini', 'thread-2']]);
        hidden_patch($this->phone, 0, $value)->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.sidebarHiddenThreads', $value)
            ->assertJsonPath('data.sections.sidebarHiddenThreads.version', 1)
            ->assertJsonPath('data.sections.sidebarHiddenThreads.updated_by', 'phone');

        $read = hidden_read($this->desktop);
        expect($read['settings']['sidebarHiddenThreads'])->toBe($value)
            ->and($read['sections']['sidebarHiddenThreads']['version'])->toBe(1)
            ->and($read['sections']['sidebarHiddenThreads']['updated_by'])->toBe('phone')
            ->and(hidden_call($this->desktop, 'GET', 'me')->json('data.profile.settings_version'))->toBe(2);
    });

    it('keeps its own version and content apart from attachedThreads and refuses a stale version', function (): void {
        $attached = hidden_value([['env-beast', 'thread-1']]);
        hidden_patch_sections($this->phone, ['attachedThreads' => ['version' => 0, 'value' => $attached]])->assertOk();

        $hidden = hidden_value([['env-beast', 'thread-2']]);
        $data = hidden_patch($this->desktop, 0, $hidden)->assertOk()->json('data');
        expect($data['sections']['attachedThreads']['version'])->toBe(1)
            ->and($data['sections']['sidebarHiddenThreads']['version'])->toBe(1)
            ->and($data['settings']['attachedThreads'])->toBe($attached)
            ->and($data['settings']['sidebarHiddenThreads'])->toBe($hidden);

        $attachedAgain = hidden_value([['env-beast', 'thread-1'], ['env-beast', 'thread-3']]);
        hidden_patch_sections($this->phone, ['attachedThreads' => ['version' => 1, 'value' => $attachedAgain]])->assertOk()
            ->assertJsonPath('data.sections.sidebarHiddenThreads.version', 1)
            ->assertJsonPath('data.settings.sidebarHiddenThreads', $hidden);

        hidden_patch($this->phone, 1, hidden_value([]))->assertOk();
        hidden_patch($this->desktop, 1, hidden_value([['env-mini', 'thread-9']]))->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.settings_version_conflict')
            ->assertJsonPath('error.details.current_version', 5)
            ->assertJsonPath('error.details.sections', ['sidebarHiddenThreads' => 2]);

        $read = hidden_read($this->phone);
        expect($read['version'])->toBe(5)
            ->and($read['settings']['sidebarHiddenThreads'])->toBe(hidden_value([]))
            ->and($read['settings']['attachedThreads'])->toBe($attachedAgain)
            ->and($read['sections']['attachedThreads']['version'])->toBe(2);
    });

    it('stores an empty list for a clear by null or by an empty list and shows it with its version', function (mixed $clear): void {
        hidden_patch($this->phone, 0, hidden_value([['env-beast', 'thread-1']]))->assertOk();

        hidden_patch($this->phone, 1, $clear)->assertOk()
            ->assertJsonPath('data.settings.sidebarHiddenThreads', ['schema' => 1, 'threads' => []])
            ->assertJsonPath('data.sections.sidebarHiddenThreads.version', 2);

        $read = hidden_read($this->desktop);
        expect($read['settings']['sidebarHiddenThreads'])->toBe(['schema' => 1, 'threads' => []])
            ->and($read['sections']['sidebarHiddenThreads']['version'])->toBe(2);

        hidden_patch($this->desktop, 2, $clear)->assertOk()
            ->assertJsonPath('data.settings.sidebarHiddenThreads', ['schema' => 1, 'threads' => []])
            ->assertJsonPath('data.sections.sidebarHiddenThreads.version', 3);
    })->with(['null' => [null], 'empty list' => [['schema' => 1, 'threads' => []]]]);

    it('stores an empty list for a first write of null', function (): void {
        hidden_patch($this->phone, 0, null)->assertOk()
            ->assertJsonPath('data.settings.sidebarHiddenThreads', ['schema' => 1, 'threads' => []])
            ->assertJsonPath('data.sections.sidebarHiddenThreads.version', 1);
    });

    it('refuses a value of the wrong shape and writes nothing', function (mixed $value, string $field): void {
        $response = hidden_patch($this->phone, 0, $value)->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');

        expect(array_keys($response->json('error.details')))->toContain($field)
            ->and($this->profile->fresh()?->settings_version)->toBe(1)
            ->and($this->profile->fresh()?->settings)->not->toHaveKey('sidebarHiddenThreads');
    })->with([
        'bare list' => [[], 'sections.sidebarHiddenThreads.value.schema'],
        'empty string' => ['', 'sections.sidebarHiddenThreads.value'],
        'no schema' => [['threads' => []], 'sections.sidebarHiddenThreads.value.schema'],
        'no threads' => [['schema' => 1], 'sections.sidebarHiddenThreads.value.threads'],
        'schema 0' => [['schema' => 0, 'threads' => []], 'sections.sidebarHiddenThreads.value.schema'],
        'schema 1001' => [['schema' => 1001, 'threads' => []], 'sections.sidebarHiddenThreads.value.schema'],
        'string schema' => [['schema' => '1', 'threads' => []], 'sections.sidebarHiddenThreads.value.schema'],
        'extra key' => [['schema' => 1, 'threads' => [], 'other' => true], 'sections.sidebarHiddenThreads.value'],
        'threads map' => [['schema' => 1, 'threads' => ['a' => ['environmentId' => 'env-beast', 'threadId' => 't']]], 'sections.sidebarHiddenThreads.value.threads'],
        'no threadId' => [['schema' => 1, 'threads' => [['environmentId' => 'env-beast']]], 'sections.sidebarHiddenThreads.value.threads.0.threadId'],
        'no environmentId' => [['schema' => 1, 'threads' => [['threadId' => 't']]], 'sections.sidebarHiddenThreads.value.threads.0.environmentId'],
        'extra entry key' => [['schema' => 1, 'threads' => [['environmentId' => 'env-beast', 'threadId' => 't', 'title' => 'x']]], 'sections.sidebarHiddenThreads.value.threads.0'],
        'colon in environment' => [hidden_value([['env:beast', 't']]), 'sections.sidebarHiddenThreads.value.threads.0.environmentId'],
        'space in environment' => [hidden_value([['env beast', 't']]), 'sections.sidebarHiddenThreads.value.threads.0.environmentId'],
        'tab in thread' => [hidden_value([['env-beast', "thread\t1"]]), 'sections.sidebarHiddenThreads.value.threads.0.threadId'],
        'long thread' => [hidden_value([['env-beast', str_repeat('t', 256)]]), 'sections.sidebarHiddenThreads.value.threads.0.threadId'],
        'duplicate' => [hidden_value([['env-beast', 'thread-1'], ['env-mini', 'thread-1'], ['env-beast', 'thread-1']]), 'sections.sidebarHiddenThreads.value.threads'],
    ]);

    it('takes 500 threads within 64 KB and refuses 501 or a larger list', function (): void {
        $short = array_map(static fn (int $index): array => ['env-beast', 'thread-'.$index], range(1, 500));
        hidden_patch($this->phone, 0, hidden_value($short))->assertOk()
            ->assertJsonCount(500, 'data.settings.sidebarHiddenThreads.threads');

        hidden_patch($this->phone, 1, hidden_value([...$short, ['env-beast', 'thread-501']]))->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['sections.sidebarHiddenThreads.value.threads']]]);

        $long = array_map(static fn (int $index): array => [str_repeat('e', 60), sprintf('%060d', $index)], range(1, 500));
        expect(ConnProfileSections::encodedSize(hidden_value($long)))->toBeGreaterThan(ConnProfileSections::MaxAttachedThreadsBytes);
        hidden_patch($this->phone, 1, hidden_value($long))->assertStatus(422)
            ->assertJsonPath('error.details', ['sections.sidebarHiddenThreads.value' => ['The section is larger than 64 KB.']]);

        expect(hidden_read($this->phone)['sections']['sidebarHiddenThreads']['version'])->toBe(1);
    });

    it('keeps the value and version through a replace that leaves it out and refuses a replace that sends it', function (): void {
        $hidden = hidden_value([['env-beast', 'thread-1']]);
        hidden_patch($this->phone, 0, $hidden)->assertOk();
        $workspaces = [['id' => 'ws-1', 'name' => 'Orbit', 'color' => 'blue', 'icon' => null]];

        $data = hidden_call($this->desktop, 'PUT', 'profiles/'.$this->profile->id.'/settings', ['version' => 2, 'settings' => ['workspaces' => $workspaces]])
            ->assertOk()->json('data');
        expect($data['version'])->toBe(3)
            ->and($data['settings']['workspaces'])->toBe($workspaces)
            ->and($data['settings']['sidebarHiddenThreads'])->toBe($hidden)
            ->and($data['sections']['sidebarHiddenThreads']['version'])->toBe(1)
            ->and($data['sections']['sidebarHiddenThreads']['updated_by'])->toBe('phone');

        hidden_call($this->desktop, 'PUT', 'profiles/'.$this->profile->id.'/settings', ['version' => 3, 'settings' => ['workspaces' => [], 'sidebarHiddenThreads' => hidden_value([])]])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

        $read = hidden_read($this->phone);
        expect($read['version'])->toBe(3)
            ->and($read['settings']['workspaces'])->toBe($workspaces)
            ->and($read['settings']['sidebarHiddenThreads'])->toBe($hidden)
            ->and($read['sections']['sidebarHiddenThreads']['version'])->toBe(1);
    });

    it('keeps to the profile access boundary and writes only the named profile', function (): void {
        $stranger = hidden_node('stranger', '10.44.0.99');
        $other = ConnProfile::query()->create(['name' => 'other', 'settings' => ['workspaces' => []], 'settings_version' => 1]);

        hidden_call($stranger, 'GET', 'profiles/'.$this->profile->id.'/settings')->assertStatus(403)
            ->assertJsonPath('error.code', 'node_access.required');
        hidden_patch($stranger, 0, hidden_value([['env-beast', 'thread-1']]))->assertStatus(403)
            ->assertJsonPath('error.code', 'node_access.required');

        hidden_patch($this->phone, 0, hidden_value([['env-beast', 'thread-1']]))->assertOk();

        expect(hidden_read($this->phone, $other)['settings'])->not->toHaveKey('sidebarHiddenThreads')
            ->and($other->fresh()?->settings_version)->toBe(1)
            ->and($this->profile->fresh()?->settings['sidebarHiddenThreads'])->toBe(hidden_value([['env-beast', 'thread-1']]));
    });

    it('never attaches, pairs, or registers what it names, and survives the attached drop of a removed Instance', function (): void {
        $environments = ConnEnvironment::query()->orderBy('id')->get()->toArray();
        $hidden = hidden_value([['env-mini', 'thread-2'], ['env-gone', 'thread-3'], ['env-beast', 'thread-1']]);

        // The phone has no access to env-mini and env-gone does not exist: form is all the Gateway checks.
        $data = hidden_patch($this->phone, 0, $hidden)->assertOk()->json('data');

        expect($data['settings'])->not->toHaveKey('attachedThreads')
            ->and($data['sections'])->not->toHaveKey('attachedThreads')
            ->and(ConnEnvironment::query()->orderBy('id')->get()->toArray())->toBe($environments)
            ->and(ConnPairing::query()->count())->toBe(0)
            ->and(ConnThread::query()->count())->toBe(0)
            ->and(ConnLaunchIntent::query()->count())->toBe(0)
            ->and(array_column(hidden_call($this->phone, 'GET', 'environments')->assertOk()->json('data'), 'environment_id'))->toBe(['env-beast']);

        hidden_patch_sections($this->desktop, ['attachedThreads' => ['version' => 0, 'value' => hidden_value([['env-beast', 'thread-1'], ['env-mini', 'thread-4']])]])->assertOk();
        DB::transaction(fn () => app(ConnInstanceLifecycle::class)->projectDestroy($this->onBeast));
        ConnEnvironment::query()->where('environment_id', 'env-mini')->delete();

        $read = hidden_read($this->desktop);
        expect($read['settings']['attachedThreads']['threads'])->toBe([])
            ->and($read['sections']['attachedThreads']['version'])->toBe(2)
            ->and($read['settings']['sidebarHiddenThreads'])->toBe($hidden)
            ->and($read['sections']['sidebarHiddenThreads']['version'])->toBe(1)
            ->and($read['sections']['sidebarHiddenThreads']['updated_by'])->toBe('phone');
    });
});
