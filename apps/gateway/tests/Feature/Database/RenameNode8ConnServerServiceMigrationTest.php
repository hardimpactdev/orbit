<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function node8ConnServerServiceMigration(): object
{
    return require database_path('migrations/2027_03_26_000000_rename_node_8_conn_server_service.php');
}

/** @param array<int, string> $services */
function seedConnServerServices(array $services): void
{
    foreach ($services as $nodeId => $service) {
        DB::table('nodes')->insert([
            'id' => $nodeId,
            'name' => 'mac-'.$nodeId,
            'public_ssh_host' => '192.0.2.'.$nodeId,
            'user' => 'nick',
        ]);
        DB::table('conn_server_nodes')->insert([
            'node_id' => $nodeId,
            'platform' => 'mac',
            'path' => '~/Applications/Conn Server.app',
            'service' => $service,
            'port' => $nodeId === 8 ? 3780 : 3773,
            'user' => 'nick',
        ]);
    }
}

it('renames only node 8 and restores its original service on rollback', function (): void {
    seedConnServerServices([
        8 => 'com.nckrtl.t3-headless',
        9 => 'com.nckrtl.t3-headless',
        11 => 'com.t3tools.t3code.service',
        45 => 'com.nckrtl.conn-server',
    ]);
    $rows = fn (): array => DB::table('conn_server_nodes')->orderBy('node_id')->get()
        ->mapWithKeys(fn (object $row): array => [$row->node_id => (array) $row])->all();
    $before = $rows();
    $expected = $before;
    $expected[8]['service'] = 'com.nckrtl.conn-server';
    $migration = node8ConnServerServiceMigration();

    $migration->up();
    expect($rows())->toBe($expected);

    $migration->down();
    expect($rows())->toBe($before);
});

it('preserves a different service on node 8 in both directions', function (): void {
    seedConnServerServices([8 => 'com.nckrtl.custom']);
    $service = fn (): string => DB::table('conn_server_nodes')->where('node_id', 8)->value('service');
    $migration = node8ConnServerServiceMigration();

    $migration->up();
    expect($service())->toBe('com.nckrtl.custom');

    $migration->down();
    expect($service())->toBe('com.nckrtl.custom');
});
