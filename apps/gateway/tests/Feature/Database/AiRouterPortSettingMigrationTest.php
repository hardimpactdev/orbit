<?php

declare(strict_types=1);

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

it('removes only the obsolete collector port and keeps setup credentials byte for byte', function (): void {
    app(AiRouterState::class)->enable(8, 'valkey', 'http://127.0.0.1:8317', 'management', 'read', 'control');
    $settings = app(SettingRepository::class);
    $gateway = new SettingScope(SettingScopeType::Gateway);
    $otherScope = new SettingScope(SettingScopeType::Node, 8);
    $settings->put($gateway, 'proxycli.process_port', '8787');
    $settings->put($otherScope, 'proxycli.process_port', 'unrelated');
    $unchanged = DB::table('settings')->where('key', '!=', 'proxycli.process_port')->orderBy('id')->get()->toJson();
    $migration = require base_path('extensions/ai-router/database/migrations/2026_10_18_162229_remove_proxycli_process_port_setting.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect($settings->get($gateway, 'proxycli.process_port'))->toBeNull()
        ->and($settings->get($otherScope, 'proxycli.process_port'))->toBe('unrelated')
        ->and(DB::table('settings')->where('key', '!=', 'proxycli.process_port')->orderBy('id')->get()->toJson())->toBe($unchanged)
        ->and(app(AiRouterState::class)->managementKey())->toBe('management');
});
