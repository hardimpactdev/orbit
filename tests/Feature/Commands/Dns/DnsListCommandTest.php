<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dnsListRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'role' => 'control',
        'host' => '10.6.0.5',
        'wireguard_address' => '10.6.0.5',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => true,
        'environment' => null,
        'platform' => 'macos',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupDnsListControlCaller(): void
{
    DB::table('nodes')->insert(dnsListRow());
}

function setupDnsListResolver(string $platform = 'macos'): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform($platform);

    return $resolver;
}

function putDnsListConfig(string $tld, string $target): void
{
    File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
    File::put(storage_path("app/orbit/dnsmasq.d/{$tld}.conf"), "address=/.{$tld}/{$target}\n");
}

function cleanupDnsListConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    cleanupDnsListConfig();
});

afterEach(function (): void {
    cleanupDnsListConfig();
});

describe('dns:list base contract', function (): void {
    it('lists configured local resolver overrides', function (): void {
        setupDnsListControlCaller();
        setupDnsListResolver();
        putDnsListConfig('test', '10.6.0.7');

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dns'])->toBe([
                [
                    'tld' => 'test',
                    'target' => '10.6.0.7',
                    'source' => 'local_resolver',
                    'resolver_backend' => 'dnsmasq',
                    'status' => 'active',
                ],
            ]);
    });

    it('returns an empty successful result when no local overrides exist', function (): void {
        setupDnsListControlCaller();
        setupDnsListResolver();

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dns'])->toBe([])
            ->and($payload['success']['meta']['count'])->toBe(0);
    });

    it('treats a missing local node row as a control caller', function (): void {
        setupDnsListResolver();
        putDnsListConfig('test', '10.6.0.7');

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['count'])->toBe(1);
    });

    it('rejects gateway callers before local resolver reads', function (): void {
        DB::table('nodes')->insert(dnsListRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]));

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('gateway');
    });

    it('rejects app callers before local resolver reads', function (): void {
        DB::table('nodes')->insert(dnsListRow([
            'name' => 'app-1',
            'role' => 'app',
            'environment' => 'development',
        ]));

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('app');
    });

    it('fails for unsupported local node role values', function (): void {
        DB::table('nodes')->insert(dnsListRow([
            'role' => 'bogus',
        ]));

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_context_invalid')
            ->and($payload['error']['meta']['reason'])->toBe('unsupported_value');
    });

    it('fails when the caller platform is unsupported', function (): void {
        setupDnsListControlCaller();
        setupDnsListResolver('unsupported');

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.unsupported_platform')
            ->and($payload['error']['meta']['platform'])->toBe('unsupported');
    });

    it('does not mutate resolver configuration', function (): void {
        setupDnsListControlCaller();
        setupDnsListResolver();
        putDnsListConfig('test', '10.6.0.7');

        $before = File::get(storage_path('app/orbit/dnsmasq.d/test.conf'));

        $exitCode = Artisan::call('dns:list', [
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(File::get(storage_path('app/orbit/dnsmasq.d/test.conf')))->toBe($before);
    });
});
