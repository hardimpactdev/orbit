<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeListHumanRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

describe('node:list human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        DB::table('nodes')->insert([
            nodeListHumanRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ]),
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);

        // Human renderer must produce prose/table output, not a JSON envelope.
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders table with ROLE NAME ENVIRONMENT PLATFORM STATUS columns', function (): void {
        DB::table('nodes')->insert([
            nodeListHumanRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ]),
            nodeListHumanRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);

        expect($output)->toContain('ROLE')
            ->and($output)->toContain('NAME')
            ->and($output)->toContain('ENVIRONMENT')
            ->and($output)->toContain('PLATFORM')
            ->and($output)->toContain('STATUS');
    });

    it('does not render wg_ip or user@wg_ip format in table', function (): void {
        DB::table('nodes')->insert([
            nodeListHumanRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
                'wireguard_address' => '10.6.0.20',
            ]),
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);

        expect($output)->not->toContain('10.6.0.20')
            ->and($output)->not->toContain('user@')
            ->and($output)->not->toContain('wg_ip');
    });

    it('renders "No nodes found." when result is empty', function (): void {
        $exitCode = Artisan::call('node:list', ['--role' => 'control']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('No nodes found');
    });

    it('groups table rows by role', function (): void {
        DB::table('nodes')->insert([
            nodeListHumanRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
            nodeListHumanRow([
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ]),
            nodeListHumanRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);

        expect($output)->toContain('gateway-1')
            ->and($output)->toContain('app-1')
            ->and($output)->toContain('control-1');
    });

    it('renders environment as em-dash for non-app nodes', function (): void {
        DB::table('nodes')->insert([
            nodeListHumanRow([
                'name' => 'gateway-1',
                'role' => 'gateway',
                'environment' => null,
            ]),
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);

        // Find the line containing gateway-1 and assert it contains an em-dash for environment
        $lines = explode("\n", $output);
        $gatewayLine = null;

        foreach ($lines as $line) {
            if (str_contains($line, 'gateway-1')) {
                $gatewayLine = $line;

                break;
            }
        }

        expect($gatewayLine)->not->toBeNull();
        expect($gatewayLine)->toContain('—');
    });

    it('renders invalid filter error prose', function (): void {
        $exitCode = Artisan::call('node:list', ['--role' => 'bogus']);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Invalid');
    });
});
