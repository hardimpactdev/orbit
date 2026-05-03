<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeGrantRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupGrantGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeGrantRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

describe('node:grant base contract', function (): void {
    it('creates a new grant and returns successfully', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0);
        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('returns idempotent success when grant already exists', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['already_granted'])->toBeTrue()
            ->and($payload['success']['data']['action'])->toBe('granted');
    });

    it('fails with node.not_found for missing consuming node', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'missing',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['meta']['field'])->toBe('consuming_node');
    });

    it('fails with node.not_found for missing serving node', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'missing',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found')
            ->and($payload['error']['meta']['field'])->toBe('serving_node');
    });

    it('fails with node.grant_policy_violation for self-grant', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.grant_policy_violation')
            ->and($payload['error']['meta']['reason'])->toBe('self_grant');
    });

    it('rejects app-node callers before side effects', function (): void {
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('rejects control-node callers with gateway_unavailable', function (): void {
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
            'is_local' => true,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('rejects unknown callers with local_context_invalid', function (): void {
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'bogus',
            'role' => 'bogus',
            'environment' => null,
            'is_local' => true,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['caller_role'])->toBe('unknown');
    });
});

describe('node:grant safety', function (): void {
    it('does not invoke ssh or external processes during grant', function (): void {
        setupGrantGatewayCaller();
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        Process::assertNothingRan();
    });

    it('makes only targeted registry mutations', function (): void {
        setupGrantGatewayCaller();
        DB::table('nodes')->insert(nodeGrantRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));
        DB::table('nodes')->insert(nodeGrantRow());

        $before = (array) DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $after = (array) DB::table('nodes')->where('name', 'app-1')->first();

        expect($after)->toBe($before);
        expect(DB::table('node_access')->count())->toBe(1);
    });
});
