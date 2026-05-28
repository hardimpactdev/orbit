<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeUpdateRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeUpdateRow([
        'name' => 'gateway-1',
    ]));
    assignNodeUpdateRowRole('gateway-1', 'gateway');
}

/**
 * @param  array<string, mixed>  $settings
 */
function assignNodeUpdateRowRole(string $nodeName, string $role, array $settings = []): void
{
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    DB::table('node_role')->insert([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings === [] ? null : json_encode($settings, JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('node:update base invocation', function (): void {
    beforeEach(function (): void {
        setupGatewayCaller();
    });

    it('updates a node and returns successfully', function (): void {
        DB::table('nodes')->insert(nodeUpdateRow());
        assignNodeUpdateRowRole('app-1', 'app-dev', ['tld' => 'test']);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
        ]);

        expect($exitCode)->toBe(0);
    });

    it('persists changes to the database', function (): void {
        DB::table('nodes')->insert(nodeUpdateRow());
        assignNodeUpdateRowRole('app-1', 'app-dev', ['tld' => 'test']);

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $node = DB::table('nodes')->where('name', 'app-1')->first();

        expect($node->host)->toBe('10.6.0.99');
    });
});

describe('node:update duplicate flag detection', function (): void {
    beforeEach(function (): void {
        setupGatewayCaller();
    });

    it('rejects duplicate field flags via ArgvInput', function (): void {
        DB::table('nodes')->insert(nodeUpdateRow());
        assignNodeUpdateRowRole('app-1', 'app-dev', ['tld' => 'test']);

        $kernel = app(Kernel::class);
        $input = new ArgvInput(['artisan', 'node:update', 'app-1', '--host=10.6.0.99', '--host=10.6.0.100', '--json']);
        $output = new BufferedOutput;

        $exitCode = $kernel->handle($input, $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, associative: true);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe("Field 'host' was supplied more than once.")
            ->and($payload['error']['meta']['field'])->toBe('host');
    });
});

describe('node:update safety', function (): void {
    beforeEach(function (): void {
        setupGatewayCaller();
    });

    it('does not invoke ssh or external processes during update', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeUpdateRow());
        assignNodeUpdateRowRole('app-1', 'app-dev', ['tld' => 'test']);

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        Process::assertNothingRan();
    });

    it('makes only targeted registry mutations', function (): void {
        DB::table('nodes')->insert(nodeUpdateRow());
        assignNodeUpdateRowRole('app-1', 'app-dev', ['tld' => 'test']);

        $before = (array) DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:update', [
            'name' => 'app-1',
            '--host' => '10.6.0.99',
            '--json' => true,
        ]);

        $after = (array) DB::table('nodes')->where('name', 'app-1')->first();

        expect($after['host'])->toBe('10.6.0.99')
            ->and($after)->not->toHaveKeys(['role', 'environment'])
            ->and($after['status'])->toBe($before['status']);
    });
});
