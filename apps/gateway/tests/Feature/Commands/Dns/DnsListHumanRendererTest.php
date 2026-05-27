<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

class FailingDnsListHumanResolver extends LocalResolver
{
    /**
     * @return array<int, array<string, string>>
     */
    public function listOverrides(): array
    {
        throw new RuntimeException('read failed');
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dnsListHumanRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'role' => 'control',
        'host' => '10.6.0.5',
        'wireguard_address' => '10.6.0.5',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => null,
        'platform' => 'macos',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupDnsListHumanControlCaller(): void
{
    DB::table('nodes')->insert(dnsListHumanRow());
}

function setupDnsListHumanResolver(string $platform = 'macos'): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform($platform);

    return $resolver;
}

function putDnsListHumanConfig(string $tld, string $target): void
{
    File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
    File::put(storage_path("app/orbit/dnsmasq.d/{$tld}.conf"), "address=/.{$tld}/{$target}\n");
}

function cleanupDnsListHumanConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    cleanupDnsListHumanConfig();
});

afterEach(function (): void {
    cleanupDnsListHumanConfig();
});

describe('dns:list human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupDnsListHumanControlCaller();
        setupDnsListHumanResolver();
        putDnsListHumanConfig('test', '10.6.0.7');

        $exitCode = Artisan::call('dns:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders a local DNS summary without a progress tree', function (): void {
        setupDnsListHumanControlCaller();
        setupDnsListHumanResolver();
        putDnsListHumanConfig('test', '10.6.0.7');

        $exitCode = Artisan::call('dns:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('TLD')
            ->and($output)->toContain('TARGET')
            ->and($output)->toContain('SOURCE')
            ->and($output)->toContain('BACKEND')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('test')
            ->and($output)->toContain('10.6.0.7')
            ->and($output)->toContain('local_resolver')
            ->and($output)->toContain('dnsmasq')
            ->and($output)->toContain('active')
            ->and($output)->not->toContain('○')
            ->and($output)->not->toContain('Working...');
    });

    it('renders empty result prose', function (): void {
        setupDnsListHumanControlCaller();
        setupDnsListHumanResolver();

        $exitCode = Artisan::call('dns:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('No local Orbit DNS overrides found.');
    });

    it('renders unsupported-platform prose without JSON envelope', function (): void {
        setupDnsListHumanControlCaller();
        setupDnsListHumanResolver('unsupported');

        $exitCode = Artisan::call('dns:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(1);
        expect($output)->toContain('This platform does not support automatic local DNS resolver inspection.')
            ->and($output)->not->toContain('"error"');
    });

    it('renders resolver-read-failed prose without JSON envelope', function (): void {
        setupDnsListHumanControlCaller();
        app()->instance(LocalResolver::class, new FailingDnsListHumanResolver);

        $exitCode = Artisan::call('dns:list');
        $output = Artisan::output();

        expect($exitCode)->toBe(1);
        expect($output)->toContain('Could not read local DNS resolver configuration.')
            ->and($output)->not->toContain('"error"');
    });
});
