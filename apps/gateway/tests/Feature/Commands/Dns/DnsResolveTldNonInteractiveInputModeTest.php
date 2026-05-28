<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dnsResolveTldNiRow(array $overrides = []): array
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

function setupDnsResolveTldNiControlCaller(): void
{
    DB::table('nodes')->insert(dnsResolveTldNiRow());
}

function setupDnsResolveTldNiMacosResolver(): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform('macos');

    return $resolver;
}

function cleanupDnsResolveTldNiConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    cleanupDnsResolveTldNiConfig();
});

afterEach(function (): void {
    cleanupDnsResolveTldNiConfig();
});

describe('dns:resolve-tld non-interactive input mode', function (): void {
    it('selects non-interactive mode without a TTY', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('forces non-interactive mode with --json', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('fails when tld is missing', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Development TLD is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'tld', 'reason' => 'missing']);
    });

    it('fails when target is missing', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Target IP address is required.')
            ->and($payload['error']['meta'])->toBe(['field' => 'target', 'reason' => 'missing']);
    });

    it('fails with forbidden target when --reset is present', function (): void {
        setupDnsResolveTldNiControlCaller();
        setupDnsResolveTldNiMacosResolver();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--reset' => true,
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('target');
    });

    it('requires --force for --reset in non-interactive mode', function (): void {
        setupDnsResolveTldNiControlCaller();
        setupDnsResolveTldNiMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            '--reset' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('destructive_consent_required')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });

    it('fails with invalid tld value', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'Test123!',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld')
            ->and($payload['error']['meta']['reason'])->toBe('invalid');
    });

    it('fails with invalid target value', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => 'not-an-ip',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('target')
            ->and($payload['error']['meta']['reason'])->toBe('invalid');
    });

    it('forbids --force without --reset', function (): void {
        setupDnsResolveTldNiControlCaller();
        setupDnsResolveTldNiMacosResolver();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
    });

    it('does not render prompts in non-interactive mode', function (): void {
        setupDnsResolveTldNiControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            '--json' => true,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(1);
        expect($output)->not->toContain('Development TLD');
    });
});
