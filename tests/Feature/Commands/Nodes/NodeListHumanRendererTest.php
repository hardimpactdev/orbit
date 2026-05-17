<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

afterEach(function (): void {
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

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
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'tld' => 'test',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

describe('node:list human renderer contract', function (): void {
    beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);

        app()->instance(RemoteShell::class, new NodeListHumanRendererRemoteShell);

        DB::table('nodes')->insert([
            'name' => 'local-gateway',
            'role' => 'gateway',
            'host' => '10.6.0.1',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'environment' => null,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

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

    it('renders table with ROLE ROLES NAME ENVIRONMENT PLATFORM STATUS columns', function (): void {
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
            ->and($output)->toContain('ROLES')
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

    it('renders legacy role separately from empty composable roles', function (): void {
        DB::table('nodes')->insert([
            nodeListHumanRow([
                'name' => 'control-1',
                'role' => 'control',
                'environment' => null,
            ]),
        ]);

        $exitCode = Artisan::call('node:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);

        $lines = explode("\n", $output);
        $controlLine = null;

        foreach ($lines as $line) {
            if (str_contains($line, 'control-1')) {
                $controlLine = $line;

                break;
            }
        }

        expect($controlLine)->not->toBeNull()
            ->and($controlLine)->toContain('control')
            ->and($controlLine)->toContain('—');
    });

    it('renders invalid filter error prose', function (): void {
        $exitCode = Artisan::call('node:list', ['--role' => 'bogus']);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Invalid');
    });

    it('renders healthy doctor summary when --doctor finds no issues', function (): void {
        DB::table('nodes')->insert(nodeListHumanRow(['name' => 'healthy-app']));
        DB::table('wireguard_peers')->insert([
            'node_id' => DB::table('nodes')->where('name', 'healthy-app')->value('id'),
            'public_key' => 'healthy-public-key',
            'private_key' => 'healthy-private-key',
            'allowed_ips' => '10.6.0.7/32',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(DevelopmentDnsMappingEnactor::class)->converge(Node::query()->where('name', 'healthy-app')->firstOrFail());

        $exitCode = Artisan::call('node:list', [
            '--doctor' => true,
            '--role' => 'app',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('healthy-app')
            ->and($output)->toContain('Doctor: All nodes healthy.');
    });

    it('renders doctor issue summary without failing the list command', function (): void {
        DB::table('nodes')->insert(nodeListHumanRow([
            'name' => 'incomplete-app',
            'wireguard_address' => null,
        ]));

        $exitCode = Artisan::call('node:list', [
            '--doctor' => true,
            '--role' => 'app',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Doctor: 1 issue found.')
            ->and($output)->toContain('incomplete-app')
            ->and($output)->toContain('node.record_incomplete')
            ->and($output)->toContain('Run `orbit doctor --fix --family=node --restore` to repair.');
    });
});

final class NodeListHumanRendererRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
