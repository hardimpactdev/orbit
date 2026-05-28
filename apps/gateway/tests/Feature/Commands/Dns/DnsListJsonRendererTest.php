<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

class FailingDnsListResolver extends LocalResolver
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
function dnsListJsonRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'host' => '10.6.0.5',
        'wireguard_address' => '10.6.0.5',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'macos',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupDnsListJsonControlCaller(): void
{
    DB::table('nodes')->insert(dnsListJsonRow());
}

function setupDnsListJsonResolver(string $platform = 'macos'): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform($platform);

    return $resolver;
}

function putDnsListJsonConfig(string $tld, string $target): void
{
    File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
    File::put(storage_path("app/orbit/dnsmasq.d/{$tld}.conf"), "address=/.{$tld}/{$target}\n");
}

function cleanupDnsListJsonConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    cleanupDnsListJsonConfig();
});

afterEach(function (): void {
    cleanupDnsListJsonConfig();
});

describe('dns:list JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns a success envelope with metadata', function (): void {
        setupDnsListJsonControlCaller();
        setupDnsListJsonResolver();
        putDnsListJsonConfig('test', '10.6.0.7');

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([
                'count' => 1,
                'resolver_backend' => 'dnsmasq',
            ]);
    });

    it('returns stable resolver entry DTO shape', function (): void {
        setupDnsListJsonControlCaller();
        setupDnsListJsonResolver();
        putDnsListJsonConfig('test', '10.6.0.7');

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dns'][0])->toBe([
                'tld' => 'test',
                'target' => '10.6.0.7',
                'source' => 'local_resolver',
                'resolver_backend' => 'dnsmasq',
                'status' => 'active',
            ]);
    });

    it('returns empty result shape', function (): void {
        setupDnsListJsonControlCaller();
        setupDnsListJsonResolver();

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe(['dns' => []])
            ->and($payload['success']['meta'])->toBe([
                'count' => 0,
                'resolver_backend' => 'dnsmasq',
            ]);
    });

    it('renders unsupported-platform error envelope', function (): void {
        setupDnsListJsonControlCaller();
        setupDnsListJsonResolver('unsupported');

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'node.unsupported_platform',
                'message' => 'This platform does not support automatic local DNS resolver inspection.',
                'meta' => [
                    'platform' => 'unsupported',
                ],
            ]);
    });

    it('renders resolver-read-failed error envelope', function (): void {
        setupDnsListJsonControlCaller();
        app()->instance(LocalResolver::class, new FailingDnsListResolver);

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'local_resolver_read_failed',
                'message' => 'Could not read local DNS resolver configuration.',
                'meta' => [
                    'resolver_backend' => 'dnsmasq',
                ],
            ]);
    });
});
