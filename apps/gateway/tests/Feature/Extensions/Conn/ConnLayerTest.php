<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Node;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

const CONN_LAYER_SERVER = 'http://10.44.0.30:3773';
const CONN_LAYER_PHONE = '10.44.0.77';

/** @return array<string, mixed> */
function conn_layer_registration(array $overrides = []): array
{
    return [
        'environment_id' => 'env-beast',
        'label' => 'beast',
        'url' => CONN_LAYER_SERVER,
        'admin_session' => 'admin-session-token',
        ...$overrides,
    ];
}

/** Fakes a T3 server that answers registration the way a T3 server's environment API does. */
function conn_layer_fake_server(array $extra = [], array $scopes = ['orchestration:read', 'orchestration:operate', 'terminal:operate', 'review:write', 'relay:read', 'access:read', 'access:write', 'relay:write']): void
{
    Http::preventStrayRequests();
    Http::fake([
        CONN_LAYER_SERVER.'/.well-known/t3/environment' => Http::response([
            'environmentId' => 'env-beast',
            'label' => 'beast',
            'platform' => ['os' => 'linux', 'arch' => 'x64'],
            'serverVersion' => '0.9.1',
            'capabilities' => [],
        ]),
        CONN_LAYER_SERVER.'/api/auth/session' => Http::response([
            'authenticated' => true,
            'auth' => ['policy' => 'remote-reachable', 'bootstrapMethods' => ['one-time-token'], 'sessionMethods' => ['bearer-access-token']],
            'scopes' => $scopes,
            'sessionMethod' => 'bearer-access-token',
            'expiresAt' => Carbon::now()->addDays(30)->toIso8601ZuluString(),
        ]),
        CONN_LAYER_SERVER.'/api/auth/clients' => Http::response([]),
        ...$extra,
    ]);
}

function conn_layer_environment(array $attributes = []): ConnEnvironment
{
    return ConnEnvironment::query()->create([
        'environment_id' => 'env-beast',
        'label' => 'beast',
        'url' => CONN_LAYER_SERVER,
        'admin_session' => 'admin-session-token',
        'admin_session_expires_at' => Carbon::now()->addDays(30),
        'registered_at' => Carbon::now(),
        ...$attributes,
    ]);
}

function conn_layer_node(string $name, string $address, LifecycleStatus $status = LifecycleStatus::Active): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'ios',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
    ]);
}

/** @return array<string, mixed> */
function conn_layer_appearance(array $overrides = []): array
{
    return [
        'schema' => 1,
        'mode' => 'system',
        'contrast' => 100,
        'themes' => [
            'light' => ['id' => 't3-code', 'source' => 'stock'],
            'dark' => ['id' => 'dark-ocean', 'name' => 'Dark Ocean', 'source' => 'published', 'appearance' => 'dark', 'colors' => ['background' => '#0b1220', 'accent' => 'rgb(10, 132, 255)', 'border' => 'oklch(30% 0.02 250 / 50%)']],
        ],
        'customThemes' => [
            ['id' => 'mine', 'name' => 'Mine', 'appearance' => 'dark', 'colors' => ['background' => '#101010'], 'variants' => ['light' => ['background' => '#ffffff']]],
        ],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function conn_layer_device(array $values = []): array
{
    return ['schema' => 1, 'values' => ['baseFontSize' => 16, 'codeWordBreak' => false, 'fontFamilySans' => 'Inter', 'fontStack' => ['Inter', 'system-ui'], 'chatWidth' => null, ...$values]];
}

function conn_layer_profile(array $settings = ['workspaces' => []], ?array $meta = null): ConnProfile
{
    return ConnProfile::query()->create(['name' => 'Nick', 'settings' => $settings, 'section_meta' => $meta]);
}

/** @return array{version: int, value: mixed} */
function conn_layer_change(mixed $value, int $version = 0): array
{
    return ['version' => $version, 'value' => $value];
}

beforeEach(function (): void {
    $this->phone = conn_layer_node('phone', CONN_LAYER_PHONE);
    $this->laptop = conn_layer_node('laptop', '10.44.0.78');
    $this->withServerVariables(['REMOTE_ADDR' => CONN_LAYER_PHONE]);
});

describe('identity', function (): void {
    it('identifies the caller as the active Node that owns its WireGuard address', function (): void {
        $this->getJson('/api/v1/conn/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->phone->id)
            ->assertJsonPath('data.name', 'phone')
            ->assertJsonPath('data.wireguard_ip', CONN_LAYER_PHONE)
            ->assertJsonPath('data.profile', null);
    });

    it('refuses a caller that is not an active Node', function (string $address): void {
        conn_layer_node('provisioning-phone', '10.44.0.90', LifecycleStatus::Provisioning);

        $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->getJson('/api/v1/conn/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    })->with(['unknown address' => '10.44.0.91', 'provisioning Node' => '10.44.0.90', 'public address' => '203.0.113.9']);

    it('answers an unknown caller before it resolves a route binding', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/conn/profiles/999/settings')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('needs no node access grant', function (): void {
        expect(Node::query()->find($this->phone->id)?->accessibleNodes()->count())->toBe(0);

        $this->getJson('/api/v1/conn/profiles')->assertOk();
    });
});

describe('profiles', function (): void {
    it('creates and lists profiles that every Node can see', function (): void {
        $this->postJson('/api/v1/conn/profiles', ['name' => 'Nick'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nick')
            ->assertJsonPath('data.settings_version', 1);
        $this->postJson('/api/v1/conn/profiles', ['name' => 'Nick'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson('/api/v1/conn/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nick');
    });

    it('binds the calling Node to one profile and keeps the binding', function (): void {
        $nick = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        $work = ConnProfile::query()->create(['name' => 'Work', 'settings' => ['workspaces' => []]]);

        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => $nick->id])
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Nick')
            ->assertJsonPath('data.id', $this->phone->id);
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.id', $nick->id);

        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => $work->id])->assertOk();
        expect(ConnProfileBinding::query()->where('node_id', $this->phone->id)->count())->toBe(1);
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.name', 'Work');
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson('/api/v1/conn/me')->assertJsonPath('data.profile', null);

        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    });
});

describe('profile settings', function (): void {
    $workspace = [
        'id' => 'ws-1',
        'name' => 'Orbit',
        'color' => 'blue',
        'icon' => 'rocket',
        'projectRefs' => ['env-beast:project-1', 'env-mini:project-2'],
        'projectKeys' => ['orbit'],
    ];

    it('replaces the settings document and increases its version', function () use ($workspace): void {
        $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        $this->getJson("/api/v1/conn/profiles/{$profile->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.settings', ['workspaces' => []]);

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", [
            'version' => 1,
            'settings' => ['workspaces' => [$workspace, ['id' => 'ws-2', 'name' => 'Home', 'color' => 'red', 'icon' => null, 'image' => 'data:image/png;base64,iVBORw0KGgo=', 'projectKeys' => ['home']]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0', $workspace)
            ->assertJsonPath('data.settings.workspaces.1.image', 'data:image/png;base64,iVBORw0KGgo=')
            ->assertJsonMissingPath('data.settings.workspaces.1.projectRefs');
        expect(Activity::query()->where('command', 'conn:profile:settings:update')->sole()->properties?->toArray()['input'])
            ->toBe(['version' => 1, 'sections' => ['workspaces']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson("/api/v1/conn/profiles/{$profile->id}/settings")
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0.projectRefs', ['env-beast:project-1', 'env-mini:project-2']);
    });

    it('refuses a write from a stale version and keeps the newer document', function () use ($workspace): void {
        $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => [$workspace]]])
            ->assertOk();

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => []]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.settings_version_conflict')
            ->assertJsonPath('error.details.current_version', 2);

        expect($profile->refresh()->settings['workspaces'])->toHaveCount(1)
            ->and($profile->settings_version)->toBe(2);
    });

    it('refuses settings outside the workspace contract', function (array $settings, string $field): void {
        $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => $settings])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors([$field], 'error.details');
    })->with([
        'unknown top-level key' => [['workspaces' => [], 'layout' => 'wide'], 'settings'],
        'no section' => [[], 'settings'],
        'project ref without environment' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => ['project-1']]]], 'settings.workspaces.0.projectRefs.0'],
        'unknown workspace key' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => [], 'secret' => 'x']]], 'settings.workspaces.0'],
        'duplicate workspace id' => [['workspaces' => [
            ['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => []],
            ['id' => 'ws-1', 'name' => 'B', 'color' => 'blue', 'icon' => null, 'projectRefs' => []],
        ]], 'settings.workspaces.0.id'],
        'image that is not a picture' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'image' => 'https://example.com/a.png', 'projectRefs' => []]]], 'settings.workspaces.0.image'],
    ]);
});

describe('profile sections', function (): void {
    $workspace = ['id' => 'ws-1', 'name' => 'Orbit', 'color' => 'blue', 'icon' => null, 'projectRefs' => [], 'projectKeys' => ['orbit']];

    it('keeps the sections a replace leaves out', function () use ($workspace): void {
        $profile = conn_layer_profile();
        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => [
            'appearance' => conn_layer_change(conn_layer_appearance()),
            'devices.iphone' => conn_layer_change(conn_layer_device()),
        ]])->assertOk();

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 2, 'settings' => ['workspaces' => [$workspace]]])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.settings.workspaces.0.id', 'ws-1')
            ->assertJsonPath('data.settings.appearance.mode', 'system')
            ->assertJsonPath('data.settings.devices.iphone.values.baseFontSize', 16)
            ->assertJsonPath('data.sections.workspaces.version', 1)
            ->assertJsonPath('data.sections.appearance.version', 1)
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.iphone']['version'] === 1);
    });

    it('replaces appearance and every device type when a replace sends them', function (): void {
        $profile = conn_layer_profile();
        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => [
            'devices.desktop' => conn_layer_change(conn_layer_device()),
            'devices.iphone' => conn_layer_change(conn_layer_device()),
        ]])->assertOk();

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 2, 'settings' => [
            'appearance' => conn_layer_appearance(['mode' => 'dark']),
            'devices' => ['desktop' => conn_layer_device(['baseFontSize' => 18])],
        ]])
            ->assertOk()
            ->assertJsonPath('data.settings.appearance.mode', 'dark')
            ->assertJsonPath('data.settings.devices.desktop.values.baseFontSize', 18)
            ->assertJsonMissingPath('data.settings.devices.iphone')
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.desktop']['version'] === 2)
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.iphone']['version'] === 2)
            ->assertJsonPath('data.sections.appearance.version', 1)
            ->assertJsonMissingPath('data.sections.workspaces');
    });

    it('refuses an empty devices map in a replace and keeps every device type', function (): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $this->patchJson($url, ['sections' => ['devices.iphone' => conn_layer_change(conn_layer_device())]])->assertOk();

        $this->putJson($url, ['version' => 2, 'settings' => ['devices' => []]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors(['settings.devices'], 'error.details');
        expect($profile->refresh()->settings)->toHaveKey('devices.iphone')
            ->and($profile->settings_version)->toBe(2);
    });

    it('patches every kind of section and reports who wrote it', function () use ($workspace): void {
        $profile = conn_layer_profile();
        $appearance = conn_layer_appearance();

        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => [
            'workspaces' => conn_layer_change([$workspace]),
            'appearance' => conn_layer_change($appearance),
            'devices.desktop' => conn_layer_change(conn_layer_device(['glassOpacity' => 85.5])),
            'devices.iphone' => conn_layer_change(conn_layer_device()),
        ]])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0.name', 'Orbit')
            ->assertJsonPath('data.settings.appearance', $appearance)
            ->assertJsonPath('data.settings.devices.desktop.values.glassOpacity', 85.5)
            ->assertJsonPath('data.settings.devices.desktop.values.chatWidth', null)
            ->assertJsonPath('data.settings.devices.iphone.values.fontStack', ['Inter', 'system-ui'])
            ->assertJsonPath('data.sections.appearance.version', 1)
            ->assertJsonPath('data.sections.appearance.updated_by', 'phone')
            ->assertJsonPath('data.sections.workspaces.version', 1)
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.iphone']['version'] === 1);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance(['mode' => 'light']), 1)]])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.sections.appearance.version', 2)
            ->assertJsonPath('data.sections.appearance.updated_by', 'laptop')
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.desktop']['updated_by'] === 'phone');
    });

    it('refuses a stale section and leaves the other sections free', function (): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $this->patchJson($url, ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance())]])->assertOk();

        $this->patchJson($url, ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance(['mode' => 'dark']))]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.settings_version_conflict')
            ->assertJsonPath('error.details.current_version', 2)
            ->assertJsonPath('error.details.sections', ['appearance' => 1]);
        expect($profile->refresh()->settings['appearance']['mode'])->toBe('system');

        $this->patchJson($url, ['sections' => ['devices.iphone' => conn_layer_change(conn_layer_device())]])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.iphone']['version'] === 1)
            ->assertJsonPath('data.sections.appearance.version', 1);
    });

    it('writes none of the sections when one is stale', function (): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $this->patchJson($url, ['sections' => ['devices.desktop' => conn_layer_change(conn_layer_device())]])->assertOk();

        $this->patchJson($url, ['sections' => [
            'appearance' => conn_layer_change(conn_layer_appearance()),
            'devices.desktop' => conn_layer_change(conn_layer_device(['baseFontSize' => 20])),
        ]])
            ->assertStatus(409)
            ->assertJsonPath('error.details.sections', ['appearance' => 0, 'devices.desktop' => 1]);

        $profile->refresh();
        expect($profile->settings)->not->toHaveKey('appearance')
            ->and($profile->settings['devices']['desktop']['values']['baseFontSize'])->toBe(16)
            ->and($profile->settings_version)->toBe(2)
            ->and(array_keys($profile->section_meta ?? []))->toBe(['devices.desktop']);
    });

    it('clears a section with null and keeps its version moving', function (): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $this->patchJson($url, ['sections' => [
            'appearance' => conn_layer_change(conn_layer_appearance()),
            'devices.iphone' => conn_layer_change(conn_layer_device()),
        ]])->assertOk();

        $this->patchJson($url, ['sections' => [
            'appearance' => conn_layer_change(null, 1),
            'devices.iphone' => conn_layer_change(null, 1),
        ]])
            ->assertOk()
            ->assertJsonMissingPath('data.settings.appearance')
            ->assertJsonMissingPath('data.settings.devices')
            ->assertJsonPath('data.sections.appearance.version', 2)
            ->assertJsonPath('data.sections', static fn (array $sections): bool => $sections['devices.iphone']['version'] === 2);

        $this->patchJson($url, ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance(), 1)]])->assertStatus(409);
        $this->patchJson($url, ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance(), 2)]])->assertOk()
            ->assertJsonPath('data.sections.appearance.version', 3);
        $this->patchJson($url, ['sections' => ['workspaces' => conn_layer_change(null)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sections.workspaces.value'], 'error.details');
    });

    it('refuses an empty string value and keeps the section', function (string $section, mixed $value, string $field): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $this->patchJson($url, ['sections' => [$section => conn_layer_change($value)]])->assertOk();

        $this->patchJson($url, ['sections' => [$section => conn_layer_change('', 1)]])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors([$field], 'error.details');
        $this->patchJson($url, ['sections' => [$section => conn_layer_change('   ', 1)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field], 'error.details');

        expect($profile->refresh()->settings_version)->toBe(2)
            ->and(data_get($profile->settings, $section))->toBe($value);
    })->with([
        'appearance' => ['appearance', conn_layer_appearance(), 'sections.appearance.value'],
        'device' => ['devices.iphone', conn_layer_device(), 'sections.devices.iphone.value'],
        'workspaces' => ['workspaces', [], 'sections.workspaces.value'],
    ]);

    it('treats a legacy row without section metadata as version 0', function () use ($workspace): void {
        $profile = conn_layer_profile(['workspaces' => [$workspace]]);
        expect(DB::table('conn_profiles')->value('section_meta'))->toBeNull();

        $response = $this->getJson("/api/v1/conn/profiles/{$profile->id}/settings")->assertOk();
        expect($response->getContent())->toContain('"sections":{}');

        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['workspaces' => conn_layer_change([], 0)]])
            ->assertOk()
            ->assertJsonPath('data.sections.workspaces.version', 1)
            ->assertJsonPath('data.settings.workspaces', []);
    });

    it('moves the profile version that /me reports on every patch', function (): void {
        $profile = conn_layer_profile();
        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => $profile->id])->assertOk();
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.settings_version', 1);

        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['devices.iphone' => conn_layer_change(conn_layer_device())]])->assertOk();
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.settings_version', 2);
        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['devices.iphone' => conn_layer_change(null, 1)]])->assertOk();
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.settings_version', 3);
    });

    it('reads a section that has a value but no metadata as version 0', function (): void {
        $profile = conn_layer_profile(['workspaces' => [], 'appearance' => conn_layer_appearance()]);

        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance(['mode' => 'dark']), 1)]])
            ->assertStatus(409)
            ->assertJsonPath('error.details.sections', ['appearance' => 0]);
        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance(['mode' => 'dark']), 0)]])
            ->assertOk()
            ->assertJsonPath('data.settings.appearance.mode', 'dark')
            ->assertJsonPath('data.sections.appearance.version', 1);
    });

    it('lists the sections with their versions on a read', function (): void {
        $profile = conn_layer_profile();
        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => ['appearance' => conn_layer_change(conn_layer_appearance())]])->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson("/api/v1/conn/profiles/{$profile->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.sections.appearance.version', 1)
            ->assertJsonPath('data.sections.appearance.updated_by', 'phone')
            ->assertJsonStructure(['data' => ['sections' => ['appearance' => ['version', 'updated_at', 'updated_by']]]]);
    });

    it('records section names and versions in Activity, never their content', function (): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $this->patchJson($url, ['sections' => [
            'appearance' => conn_layer_change(conn_layer_appearance()),
            'devices.iphone' => conn_layer_change(conn_layer_device()),
        ]])->assertOk();
        $this->putJson($url, ['version' => 2, 'settings' => ['appearance' => conn_layer_appearance(['mode' => 'light']), 'devices' => ['iphone' => conn_layer_device()]]])->assertOk();

        $patch = Activity::query()->where('command', 'conn:profile:settings:patch')->sole();
        $update = Activity::query()->where('command', 'conn:profile:settings:update')->sole();
        expect($patch->properties?->toArray()['input'])->toBe(['sections' => ['appearance' => 0, 'devices.iphone' => 0]])
            ->and($update->properties?->toArray()['input'])->toBe(['version' => 2, 'sections' => ['appearance', 'devices']]);
        foreach ([$patch, $update] as $activity) {
            expect(json_encode($activity->properties))->not->toContain('dark-ocean')->not->toContain('#0b1220')->not->toContain('Inter');
        }
    });

    it('refuses sections outside the contract', function (array $sections, string $field): void {
        $profile = conn_layer_profile();

        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => $sections])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors([$field], 'error.details');
        expect($profile->refresh()->settings_version)->toBe(1);
    })->with([
        'unknown section' => [['theme' => conn_layer_change(['mode' => 'dark'])], 'sections'],
        'bare devices section' => [['devices' => conn_layer_change(['desktop' => conn_layer_device()])], 'sections'],
        'device type with capitals' => [['devices.Phone' => conn_layer_change(conn_layer_device())], 'sections'],
        'device type that is too short' => [['devices.x' => conn_layer_change(conn_layer_device())], 'sections'],
        'no sections' => [[], 'sections'],
        'missing version' => [['appearance' => ['value' => conn_layer_appearance()]], 'sections.appearance.version'],
        'negative version' => [['appearance' => conn_layer_change(conn_layer_appearance(), -1)], 'sections.appearance.version'],
        'missing value' => [['appearance' => ['version' => 0]], 'sections.appearance.value'],
        'unknown change key' => [['appearance' => [...conn_layer_change(conn_layer_appearance()), 'force' => true]], 'sections.appearance'],
        'mode outside the list' => [['appearance' => conn_layer_change(conn_layer_appearance(['mode' => 'sepia']))], 'sections.appearance.value.mode'],
        'contrast too low' => [['appearance' => conn_layer_change(conn_layer_appearance(['contrast' => 49]))], 'sections.appearance.value.contrast'],
        'contrast too high' => [['appearance' => conn_layer_change(conn_layer_appearance(['contrast' => 201]))], 'sections.appearance.value.contrast'],
        'contrast that is not an integer' => [['appearance' => conn_layer_change(conn_layer_appearance(['contrast' => '100']))], 'sections.appearance.value.contrast'],
        'unknown appearance key' => [['appearance' => conn_layer_change(conn_layer_appearance(['accent' => 'blue']))], 'sections.appearance.value'],
        'theme source outside the list' => [['appearance' => conn_layer_change(conn_layer_appearance(['themes' => ['light' => ['id' => 'x', 'source' => 'remote']]]))], 'sections.appearance.value.themes.light.source'],
        'theme slot without id' => [['appearance' => conn_layer_change(conn_layer_appearance(['themes' => ['dark' => ['source' => 'stock']]]))], 'sections.appearance.value.themes.dark.id'],
        'theme slot for another appearance' => [['appearance' => conn_layer_change(conn_layer_appearance(['themes' => ['sepia' => ['id' => 'x', 'source' => 'stock']]]))], 'sections.appearance.value.themes'],
        'color value with a url' => [['appearance' => conn_layer_change(conn_layer_appearance(['themes' => ['dark' => ['id' => 'x', 'source' => 'custom', 'colors' => ['background' => 'url(https://example.com/a.png)']]]]))], 'sections.appearance.value.themes.dark.colors.background'],
        'color value with markup' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => [['id' => 'a', 'name' => 'A', 'appearance' => 'dark', 'colors' => ['background' => '<script>']]]]))], 'sections.appearance.value.customThemes.0.colors.background'],
        'color value that is too long' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => [['id' => 'a', 'name' => 'A', 'appearance' => 'dark', 'colors' => ['background' => str_repeat('a', 65)]]]]))], 'sections.appearance.value.customThemes.0.colors.background'],
        'color role with a dash' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => [['id' => 'a', 'name' => 'A', 'appearance' => 'dark', 'colors' => ['background-color' => '#fff']]]]))], 'sections.appearance.value.customThemes.0.colors'],
        'too many colors' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => [['id' => 'a', 'name' => 'A', 'appearance' => 'dark', 'colors' => array_fill_keys(array_map(static fn (int $i): string => "role{$i}", range(1, 81)), '#fff')]]]))], 'sections.appearance.value.customThemes.0.colors'],
        'empty colors map' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => [['id' => 'a', 'name' => 'A', 'appearance' => 'dark', 'colors' => []]]]))], 'sections.appearance.value.customThemes.0.colors'],
        'duplicate custom theme id' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => [
            ['id' => 'a', 'name' => 'A', 'appearance' => 'dark', 'colors' => ['background' => '#000']],
            ['id' => 'a', 'name' => 'B', 'appearance' => 'light', 'colors' => ['background' => '#fff']],
        ]]))], 'sections.appearance.value.customThemes.0.id'],
        'too many custom themes' => [['appearance' => conn_layer_change(conn_layer_appearance(['customThemes' => array_map(static fn (int $i): array => ['id' => "t{$i}", 'name' => 'T', 'appearance' => 'dark', 'colors' => ['background' => '#000']], range(1, 51))]))], 'sections.appearance.value.customThemes'],
        'device schema missing' => [['devices.desktop' => conn_layer_change(['values' => ['a' => 1]])], 'sections.devices.desktop.value.schema'],
        'device without values' => [['devices.desktop' => conn_layer_change(['schema' => 1])], 'sections.devices.desktop.value.values'],
        'device with an empty values map' => [['devices.desktop' => conn_layer_change(['schema' => 1, 'values' => []])], 'sections.devices.desktop.value.values'],
        'device value key in snake case' => [['devices.desktop' => conn_layer_change(conn_layer_device(['font_size' => 12]))], 'sections.devices.desktop.value.values'],
        'device value that is an object' => [['devices.desktop' => conn_layer_change(conn_layer_device(['chat' => ['width' => 1]]))], 'sections.devices.desktop.value.values.chat'],
        'device value that is a list of lists' => [['devices.desktop' => conn_layer_change(conn_layer_device(['chat' => [[1]]]))], 'sections.devices.desktop.value.values.chat'],
        'device value that is a long string' => [['devices.desktop' => conn_layer_change(conn_layer_device(['note' => str_repeat('a', 513)]))], 'sections.devices.desktop.value.values.note'],
        'device value list that is too long' => [['devices.desktop' => conn_layer_change(conn_layer_device(['fonts' => range(1, 33)]))], 'sections.devices.desktop.value.values.fonts'],
        'too many device values' => [['devices.desktop' => conn_layer_change(['schema' => 1, 'values' => array_fill_keys(array_map(static fn (int $i): string => "key{$i}", range(1, 129)), 1)])], 'sections.devices.desktop.value.values'],
        'workspaces that are not a list' => [['workspaces' => conn_layer_change(['id' => 'ws-1'])], 'sections.workspaces.value'],
        'duplicate workspace id' => [['workspaces' => conn_layer_change([
            ['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null],
            ['id' => 'ws-1', 'name' => 'B', 'color' => 'blue', 'icon' => null],
        ])], 'sections.workspaces.value.0.id'],
    ]);

    it('refuses a section that is larger than its limit', function (string $section, array $value, string $field): void {
        $profile = conn_layer_profile();

        $this->patchJson("/api/v1/conn/profiles/{$profile->id}/settings", ['sections' => [$section => conn_layer_change($value)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field], 'error.details');
        expect($profile->refresh()->settings_version)->toBe(1);
    })->with([
        'appearance over 512 KB' => ['appearance', conn_layer_appearance(['customThemes' => array_map(static fn (int $i): array => [
            'id' => "theme{$i}", 'name' => 'Theme', 'appearance' => 'dark',
            'colors' => array_fill_keys(array_map(static fn (int $c): string => 'role'.str_repeat('a', 50).$c, range(1, 80)), str_repeat('a', 64)),
            'variants' => ['light' => array_fill_keys(array_map(static fn (int $c): string => 'role'.str_repeat('a', 50).$c, range(1, 80)), str_repeat('a', 64))],
        ], range(1, 40))]), 'sections.appearance.value'],
        'device section over 64 KB' => ['devices.desktop', conn_layer_device(array_fill_keys(array_map(static fn (int $i): string => "list{$i}", range(1, 6)), array_fill(0, 32, str_repeat('a', 512)))), 'sections.devices.desktop.value'],
    ]);

    it('refuses a ninth device type', function (): void {
        $profile = conn_layer_profile();
        $url = "/api/v1/conn/profiles/{$profile->id}/settings";
        $sections = [];
        foreach (range(1, 8) as $i) {
            $sections["devices.type-{$i}"] = conn_layer_change(conn_layer_device());
        }
        $this->patchJson($url, ['sections' => $sections])->assertOk();

        $this->patchJson($url, ['sections' => ['devices.type-9' => conn_layer_change(conn_layer_device())]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings'], 'error.details');
        $this->patchJson($url, ['sections' => [...$sections, 'devices.type-9' => conn_layer_change(conn_layer_device())]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sections'], 'error.details');
        $this->putJson($url, ['version' => 2, 'settings' => ['devices' => array_combine(
            array_map(static fn (int $i): string => "type-{$i}", range(1, 9)),
            array_fill(0, 9, conn_layer_device()),
        )]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.devices'], 'error.details');

        $this->patchJson($url, ['sections' => ['devices.type-1' => conn_layer_change(null, 1)]])->assertOk();
        $this->patchJson($url, ['sections' => ['devices.type-9' => conn_layer_change(conn_layer_device())]])->assertOk();
        expect($profile->refresh()->settings['devices'])->toHaveCount(8);
    });

    it('refuses a document that grows past 4 MB', function (): void {
        $profile = conn_layer_profile();
        $picture = 'data:image/png;base64,'.str_repeat('A', 200_000 - 22);
        $workspaces = array_map(static fn (int $i): array => ['id' => "ws-{$i}", 'name' => 'W', 'color' => 'blue', 'icon' => null, 'image' => $picture], range(1, 25));

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => $workspaces]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings'], 'error.details');
        expect($profile->refresh()->settings_version)->toBe(1);

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => array_slice($workspaces, 0, 15)]])->assertOk();
    });

    it('applies the same appearance rules to a replace', function (): void {
        $profile = conn_layer_profile();

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['appearance' => conn_layer_appearance(['contrast' => 300])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.appearance.contrast'], 'error.details');
        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['appearance' => null]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.appearance'], 'error.details');
        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['devices' => ['Desktop' => conn_layer_device()]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.devices'], 'error.details');
        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['devices' => ['desktop' => conn_layer_device(['note' => ['a' => 1]])]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.devices.desktop.values.note'], 'error.details');
    });
});

describe('environment registration', function (): void {
    it('checks the admin session with the T3 server and stores it encrypted', function (): void {
        conn_layer_fake_server();

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())
            ->assertOk()
            ->assertJsonPath('data.environment_id', 'env-beast')
            ->assertJsonPath('data.url', CONN_LAYER_SERVER)
            ->assertJsonPath('data.server_version', '0.9.1')
            ->assertJsonPath('data.registered_by', 'phone')
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonMissingPath('data.admin_session');

        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/session'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));

        $environment = ConnEnvironment::query()->sole();
        $stored = DB::table('conn_environments')->value('admin_session');
        expect($environment->admin_session)->toBe('admin-session-token')
            ->and($stored)->not->toContain('admin-session-token')
            ->and($environment->admin_session_expires_at->isAfter(Carbon::now()->addDays(29)))->toBeTrue()
            ->and(Activity::query()->where('command', 'conn:environment:register')->sole()->properties?->toArray()['input'])
            ->toBe(['environment_id' => 'env-beast', 'label' => 'beast', 'url' => CONN_LAYER_SERVER])
            ->and(json_encode(Activity::query()->sole()->properties))->not->toContain('admin-session-token');

        $this->getJson('/api/v1/conn/environments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'beast');
    });

    it('replaces the session on re-registration and revokes the earlier Gateway sessions', function (): void {
        conn_layer_fake_server([
            CONN_LAYER_SERVER.'/api/auth/clients/revoke' => Http::response(['revoked' => true]),
            CONN_LAYER_SERVER.'/api/auth/clients' => Http::response([
                ['sessionId' => 'new', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => true],
                ['sessionId' => 'old', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => false],
                ['sessionId' => 'phone', 'client' => ['label' => 'iPhone', 'deviceType' => 'mobile'], 'current' => false],
            ]),
        ]);
        conn_layer_environment(['label' => 'old label', 'admin_session' => 'old-session']);

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())->assertOk()->assertJsonPath('data.label', 'beast');

        expect(ConnEnvironment::query()->count())->toBe(1)
            ->and(ConnEnvironment::query()->sole()->admin_session)->toBe('admin-session-token');
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] === 'old'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));
        Http::assertNotSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && in_array($request['sessionId'], ['new', 'phone'], true));
    });

    it('refuses a URL that serves another environment before it sends the session', function (): void {
        conn_layer_fake_server();

        $this->postJson('/api/v1/conn/environments', conn_layer_registration(['environment_id' => 'env-other']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'conn.environment_mismatch');

        Http::assertNotSent(static fn (HttpRequest $request): bool => str_ends_with($request->url(), '/api/auth/session'));
        expect(ConnEnvironment::query()->count())->toBe(0);
    });

    it('refuses a session that is not an admin session', function (): void {
        conn_layer_fake_server(scopes: ['orchestration:read', 'orchestration:operate', 'terminal:operate', 'review:write', 'relay:read']);

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'conn.admin_scope_missing');
        expect(ConnEnvironment::query()->count())->toBe(0);
    });

    it('reports a session the T3 server does not accept', function (): void {
        conn_layer_fake_server([
            CONN_LAYER_SERVER.'/api/auth/session' => Http::response([
                'authenticated' => false,
                'auth' => ['policy' => 'remote-reachable', 'bootstrapMethods' => ['one-time-token'], 'sessionMethods' => ['bearer-access-token']],
            ]),
        ]);

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.session_rejected')
            ->assertJsonPath('error.details.operation', 'session');
        expect(ConnEnvironment::query()->count())->toBe(0);
    });

    it('refuses a registration without an admin session', function (): void {
        $this->postJson('/api/v1/conn/environments', conn_layer_registration(['admin_session' => 'two words']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_session'], 'error.details');
    });
});

describe('pairing', function (): void {
    it('mints a pairing link for the calling Node and records it', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            CONN_LAYER_SERVER.'/api/auth/pairing-token' => Http::response([
                'id' => 'link-1',
                'credential' => 'device-pairing-token',
                'label' => 'ignored',
                'expiresAt' => '2026-10-08T12:05:00.000Z',
            ]),
        ]);
        conn_layer_environment();

        $response = $this->postJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertCreated()
            ->assertJsonPath('data.pairing_url', CONN_LAYER_SERVER.'/pair#token=device-pairing-token')
            ->assertJsonPath('data.pairing.environment_id', 'env-beast')
            ->assertJsonPath('data.pairing.node_name', 'phone')
            ->assertJsonPath('data.pairing.revoked_at', null);

        $pairing = ConnPairing::query()->sole();
        expect($pairing->pairing_link_id)->toBe('link-1')
            ->and($pairing->client_label)->toStartWith('phone via Orbit ')
            ->and($response->json('data.pairing.client_label'))->toBe($pairing->client_label);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/pairing-token'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token')
            && $request['label'] === $pairing->client_label
            && in_array('access:read', $request['scopes'], true)
            && in_array('access:write', $request['scopes'], true)
            && ! in_array('review:write', $request['scopes'], true));

        $this->getJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.pairing_link_id')
            ->assertJsonPath('data.0.client_label', $pairing->client_label);
        expect(Activity::query()->where('command', 'conn:pairing:create')->value('caller_ip'))->toBe(CONN_LAYER_PHONE);
    });

    it('refuses to mint with an expired admin session', function (): void {
        Http::preventStrayRequests();
        conn_layer_environment(['admin_session_expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.session_expired');
        $this->getJson('/api/v1/conn/environments')->assertJsonPath('data.0.status', 'session_expired');
    });

    it('reports an unreachable T3 server', function (): void {
        Http::fake([CONN_LAYER_SERVER.'/*' => Http::failedConnection()]);
        conn_layer_environment();

        $this->postJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
        expect(ConnPairing::query()->count())->toBe(0);
    });

    it('answers 404 for an environment that never registered', function (): void {
        $this->postJson('/api/v1/conn/environments/env-missing/pairings')->assertNotFound();
    });
});

describe('revoke', function (): void {
    it("revokes the Node's sessions and unused links on the server", function (): void {
        Http::preventStrayRequests();
        $environment = conn_layer_environment();
        $phone = $this->phone;
        $laptop = $this->laptop;
        foreach ([[$phone, 'link-1', 'phone via Orbit a'], [$phone, 'link-2', 'phone via Orbit b'], [$laptop, 'link-3', 'laptop via Orbit c']] as [$node, $link, $label]) {
            ConnPairing::query()->create([
                'node_id' => $node->id,
                'conn_environment_id' => $environment->id,
                'pairing_link_id' => $link,
                'client_label' => $label,
                'expires_at' => Carbon::now()->addMinutes(5),
            ]);
        }
        Http::fake([
            CONN_LAYER_SERVER.'/api/auth/clients/revoke' => Http::response(['revoked' => true]),
            CONN_LAYER_SERVER.'/api/auth/pairing-links/revoke' => Http::response(['revoked' => false]),
            CONN_LAYER_SERVER.'/api/auth/clients' => Http::response([
                ['sessionId' => 'gateway', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => true],
                ['sessionId' => 'phone-session', 'client' => ['label' => 'phone via Orbit a', 'deviceType' => 'mobile'], 'current' => false],
                ['sessionId' => 'laptop-session', 'client' => ['label' => 'laptop via Orbit c', 'deviceType' => 'desktop'], 'current' => false],
            ]),
        ]);

        $this->postJson('/api/v1/conn/environments/env-beast/pairings/revoke')
            ->assertOk()
            ->assertJsonPath('data.node_id', $phone->id)
            ->assertJsonPath('data.revoked_sessions', 1)
            ->assertJsonCount(2, 'data.pairings');

        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] === 'phone-session'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));
        Http::assertNotSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] !== 'phone-session');
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/pairing-links/revoke' && $request['id'] === 'link-2');
        expect(ConnPairing::query()->whereNotNull('revoked_at')->pluck('pairing_link_id')->all())->toBe(['link-1', 'link-2']);

        $this->postJson('/api/v1/conn/environments/env-beast/pairings/revoke', ['node_id' => $laptop->id])
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 1);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke' && $request['sessionId'] === 'laptop-session');
    });

    it('calls nothing when the Node holds no pairing on the server', function (): void {
        Http::preventStrayRequests();
        conn_layer_environment();

        $this->postJson('/api/v1/conn/environments/env-beast/pairings/revoke')
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 0)
            ->assertJsonPath('data.pairings', []);
        Http::assertNothingSent();
    });
});
