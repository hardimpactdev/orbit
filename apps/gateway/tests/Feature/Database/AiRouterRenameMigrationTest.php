<?php

declare(strict_types=1);

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

beforeEach(function (): void {
    Schema::drop('ai_router_cutovers');
    DB::statement('ALTER TABLE task_vms RENAME COLUMN ai_router_origin TO model_proxy_origin');
});

function ai_router_rename_migration(): object
{
    return require base_path('extensions/ai-router/database/migrations/2026_11_16_000001_rename_ai_router_state.php');
}

it('renames stored switch and settings without decrypting or changing credentials and anchor hashes', function (): void {
    $scope = new SettingScope(SettingScopeType::Gateway);
    $settings = app(SettingRepository::class);
    $old = 'proxycli';
    foreach ([$old.'.enabled' => '1', 'extension.'.$old.'.enabled' => '1', $old.'.node_id' => '17', $old.'.cliproxy_url' => 'http://10.44.0.3:8317'] as $key => $value) {
        $settings->put($scope, $key, $value);
    }
    $settings->put($scope, $old.'.cliproxy_management_key', 'management-sentinel', SettingValueProtection::Secret);
    $settings->put($scope, 'compute.model_proxy.anchor.'.hash('sha256', 'http://10.44.0.3:8317'), 'anchor-sentinel', SettingValueProtection::Secret);
    $ciphertexts = DB::table('settings')->where('is_secret', true)->pluck('value', 'id')->all();

    ai_router_rename_migration()->up();

    expect($settings->get($scope, 'extension.ai-router.enabled'))->toBe('1')
        ->and(app(AiRouterState::class)->routerUrl())->toBe('http://10.44.0.3:8317')
        ->and(app(AiRouterState::class)->managementKey())->toBe('management-sentinel')
        ->and($settings->get($scope, 'ai_router.anchor.'.hash('sha256', 'http://10.44.0.3:8317')))->toBe('anchor-sentinel')
        ->and(DB::table('settings')->where('is_secret', true)->pluck('value', 'id')->all())->toBe($ciphertexts)
        ->and(DB::table('settings')->where('key', 'like', '%'.$old.'%')->count())->toBe(0);
});

it('refuses conflicting settings and rolls back earlier renames', function (): void {
    $settings = app(SettingRepository::class);
    $scope = new SettingScope(SettingScopeType::Gateway);
    $settings->put($scope, 'extension.proxycli.enabled', '1');
    $settings->put($scope, 'proxycli.read_token', 'old');
    $settings->put($scope, 'ai_router.read_token', 'new');
    $before = DB::table('settings')->orderBy('id')->get()->toJson();

    expect(fn () => ai_router_rename_migration()->up())->toThrow(RuntimeException::class, 'Both retired and current')
        ->and(DB::table('settings')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(Schema::hasTable('ai_router_cutovers'))->toBeFalse();
    $settings->delete($scope, 'ai_router.read_token');
    ai_router_rename_migration()->up();
    expect($settings->get($scope, 'ai_router.read_token'))->toBe('old');
});

it('records an automatic cutover for a running collector without stopping it in the migration', function (): void {
    $node = Node::query()->create(['name' => 'services', 'status' => 'active', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), 'proxycli.node_id', (string) $node->id);
    $id = DB::table('processes')->insertGetId([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'cli-proxy-api-collector',
        'runtime' => 'systemd', 'runtime_config' => json_encode(['command' => ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'], 'environment' => []], JSON_THROW_ON_ERROR), 'desired_state' => 'running', 'status' => 'active',
    ]);
    ai_router_rename_migration()->up();
    expect(DB::table('processes')->where('id', $id)->value('desired_state'))->toBe('running')
        ->and(DB::table('ai_router_cutovers')->where('process_id', $id)->exists())->toBeTrue();
});

it('renames only the stopped configured collector and preserves every environment value and source row', function (): void {
    $node = Node::query()->create(['name' => 'services', 'status' => 'active', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    $other = Node::query()->create(['name' => 'other', 'status' => 'active', 'public_ssh_host' => '192.0.2.4', 'wireguard_ip' => '10.44.0.4']);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), 'proxycli.node_id', (string) $node->id);
    $oldEnvironment = ['PROXYCLI_READ_TOKEN' => 'unchanged-read-token', 'PROXYCLI_PORT' => '8787'];
    $config = ['command' => ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'], 'environment' => $oldEnvironment];
    $id = DB::table('processes')->insertGetId([
        'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'cli-proxy-api-collector',
        'runtime' => 'systemd', 'runtime_config' => json_encode($config, JSON_THROW_ON_ERROR), 'desired_state' => 'stopped', 'status' => 'active',
    ]);
    $unrelatedId = DB::table('processes')->insertGetId([
        'owner_type' => Node::class, 'owner_id' => $other->id, 'name' => 'cli-proxy-api-collector',
        'runtime' => 'systemd', 'runtime_config' => '{}', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $unrelated = DB::table('processes')->where('id', $unrelatedId)->first();
    DB::table('ai_router_process_sources')->insert(['process_id' => $id, 'source_revision' => str_repeat('a', 64), 'activation_pending' => true]);
    $source = DB::table('ai_router_process_sources')->where('process_id', $id)->first();

    ai_router_rename_migration()->up();

    $process = DB::table('processes')->where('id', $id)->first();
    $renamed = json_decode($process->runtime_config, true, flags: JSON_THROW_ON_ERROR);
    expect($process->name)->toBe('ai-router-collector')
        ->and($process->desired_state)->toBe('stopped')
        ->and($renamed['environment'])->toBe(['AI_ROUTER_READ_TOKEN' => 'unchanged-read-token', 'AI_ROUTER_PORT' => '8787'])
        ->and($renamed['command'])->toBe(['/usr/bin/python3', '/var/lib/orbit/ai-router/collector.py'])
        ->and(DB::table('ai_router_process_sources')->where('process_id', $id)->first())->toEqual($source)
        ->and(DB::table('processes')->where('id', $unrelatedId)->first())->toEqual($unrelated);
});

it('renames the stored origin column while preserving held model keys and origin values byte for byte', function (): void {
    $id = DB::table('task_vms')->insertGetId([
        'provider' => 'incus', 'name' => 'held-vm', 'state' => 'ready', 'wireguard_ip' => '10.44.0.130',
        'model_key' => 'encrypted-group-key-sentinel', 'model_proxy_origin' => 'http://10.44.0.3:8317',
    ]);
    $before = (array) DB::table('task_vms')->where('id', $id)->first();

    ai_router_rename_migration()->up();

    $after = (array) DB::table('task_vms')->where('id', $id)->first();
    $before['ai_router_origin'] = $before['model_proxy_origin'];
    unset($before['model_proxy_origin']);
    expect($after)->toEqual($before);
});

it('renames stored error codes and invalidates only the retired footprint receipt', function (): void {
    $node = Node::query()->create(['name' => 'receipt-node', 'status' => 'active', 'public_ssh_host' => '192.0.2.3']);
    DB::table('node_footprints')->insert(['node_id' => $node->id, 'artifacts' => json_encode(['proxycli' => 'old-digest', 'caddy' => 'keep-digest'], JSON_THROW_ON_ERROR)]);
    // A separate error-code table covers the migration's scalar-column contract without unrelated Activity policy.
    DB::statement('CREATE TABLE rename_error_records (id INTEGER PRIMARY KEY, error_code TEXT, evidence TEXT)');
    try {
        foreach (['proxycli.disabled', 'process.required_by_proxycli', 'resource.not_found'] as $code) {
            DB::table('rename_error_records')->insert(['error_code' => $code, 'evidence' => 'proxycli historical text']);
        }

        ai_router_rename_migration()->up();

        expect(DB::table('rename_error_records')->orderBy('id')->pluck('error_code')->all())->toBe(['ai_router.disabled', 'process.required_by_ai_router', 'resource.not_found'])
            ->and(DB::table('rename_error_records')->where('evidence', 'proxycli historical text')->count())->toBe(3)
            ->and(json_decode(DB::table('node_footprints')->value('artifacts'), true, flags: JSON_THROW_ON_ERROR))->toBe(['caddy' => 'keep-digest']);
    } finally {
        DB::statement('DROP TABLE rename_error_records');
    }
});
