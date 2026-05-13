<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dnsResolveTldRow(array $overrides = []): array
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

function setupDnsResolveTldControlCaller(): void
{
    DB::table('nodes')->insert(dnsResolveTldRow());
}

function setupDnsResolveTldMacosResolver(): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform('macos');

    return $resolver;
}

function cleanupDnsResolveTldConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    cleanupDnsResolveTldConfig();
});

afterEach(function (): void {
    cleanupDnsResolveTldConfig();
});

describe('dns:resolve-tld base contract', function (): void {
    it('resolves a TLD and returns successfully', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: '/opt/homebrew', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);

        expect($exitCode)->toBe(0);
        expect(File::exists(storage_path('app/orbit/dnsmasq.d/test.conf')))->toBeTrue();
    });

    it('logs dns resolve activity', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: '/opt/homebrew', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $entry = Activity::query()->first();

        expect($exitCode)->toBe(0);
        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('dns:resolve-tld');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('tld'))->toBe('test');
        expect($entry->properties->get('target'))->toBe('10.6.0.7');
        expect($entry->properties->get('action'))->toBe('resolve');
        expect($entry->properties->get('status'))->toBe('resolved');
        expect($entry->properties->get('changed'))->toBeTrue();
        expect($entry->properties->get('resolver_backend'))->toBe('dnsmasq');
    });

    it('returns idempotent success when TLD is already resolved to the same target', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
        File::put(storage_path('app/orbit/dnsmasq.d/test.conf'), "address=/.test/10.6.0.7\n");

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dns']['status'])->toBe('already_resolved')
            ->and($payload['success']['data']['dns']['changed'])->toBeFalse();
    });

    it('resets a TLD and returns successfully', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
        File::put(storage_path('app/orbit/dnsmasq.d/test.conf'), "address=/.test/10.6.0.7\n");

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'test -f /etc/resolver/test' => Process::result(output: '', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            '--reset' => true,
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dns']['status'])->toBe('reset')
            ->and($payload['success']['data']['dns']['changed'])->toBeTrue()
            ->and(File::exists(storage_path('app/orbit/dnsmasq.d/test.conf')))->toBeFalse();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->properties->get('type'))->toBe('destructive');
        expect($entry->properties->get('action'))->toBe('reset');
        expect($entry->properties->get('status'))->toBe('reset');
    });

    it('returns idempotent success when reset is already absent', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'test -f /etc/resolver/test' => Process::result(output: '', errorOutput: '', exitCode: 1),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            '--reset' => true,
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['dns']['status'])->toBe('already_absent')
            ->and($payload['success']['data']['dns']['changed'])->toBeFalse();
    });

    it('fails with validation_failed when tld is missing', function (): void {
        setupDnsResolveTldControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('fails with validation_failed when target is missing', function (): void {
        setupDnsResolveTldControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('target');
    });

    it('fails with validation_failed for invalid tld', function (): void {
        setupDnsResolveTldControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => '.test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('fails with validation_failed for invalid target', function (): void {
        setupDnsResolveTldControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => 'not-an-ip',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('target');
    });

    it('rejects gateway callers before side effects', function (): void {
        config(['orbit.is_gateway' => true]);

        Process::fake();
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('gateway');

        Process::assertNothingRan();
    });

    it('fails with unsupported_platform on non-macos', function (): void {
        setupDnsResolveTldControlCaller();

        $resolver = app(LocalResolver::class);
        $resolver->setPlatform('linux');

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.unsupported_platform')
            ->and($payload['error']['meta']['platform'])->toBe('linux');
    });

    it('does not write gateway intent or public DNS', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: '/opt/homebrew', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);

        // No database mutations outside local DNS config
        expect(DB::table('nodes')->count())->toBe(1);
    });
});

describe('dns:resolve-tld safety', function (): void {

    it('makes only targeted local resolver mutations', function (): void {
        setupDnsResolveTldControlCaller();
        setupDnsResolveTldMacosResolver();

        File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
        File::put(storage_path('app/orbit/dnsmasq.d/other.conf'), "address=/.other/10.6.0.8\n");

        Process::fake([
            'which dnsmasq' => Process::result(output: '/opt/homebrew/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: '/opt/homebrew', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);

        expect(File::exists(storage_path('app/orbit/dnsmasq.d/test.conf')))->toBeTrue();
        expect(File::exists(storage_path('app/orbit/dnsmasq.d/other.conf')))->toBeTrue();
    });
});
