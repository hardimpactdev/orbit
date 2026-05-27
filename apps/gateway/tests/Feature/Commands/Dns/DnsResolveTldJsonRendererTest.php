<?php

declare(strict_types=1);

use App\Console\Commands\DnsResolveTldCommand;
use App\Services\Dns\LocalResolver;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dnsResolveTldJsonRow(array $overrides = []): array
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

function setupDnsResolveTldJsonControlCaller(): void
{
    DB::table('nodes')->insert(dnsResolveTldJsonRow());
}

function setupDnsResolveTldJsonMacosResolver(): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform('macos');

    return $resolver;
}

function invokeDnsResolveTldFailCommandJson(bool $json, string $code, string $message, array $meta, ?array $data = null): array
{
    $command = new DnsResolveTldCommand;
    $command->setLaravel(app());
    $input = new ArrayInput(array_merge([
        'tld' => 'dummy',
        'target' => '10.6.0.7',
    ], $json ? ['--json' => true] : []));
    $output = new BufferedOutput;

    $merge = new ReflectionMethod($command, 'mergeApplicationDefinition');
    $merge->setAccessible(true);
    $merge->invoke($command);

    $definition = $command->getDefinition();
    $input->bind($definition);
    $input->validate();

    $init = new ReflectionMethod($command, 'initialize');
    $init->setAccessible(true);
    $init->invoke($command, $input, $output);

    $inputProp = (new ReflectionClass(Command::class))->getProperty('input');
    $inputProp->setAccessible(true);
    $inputProp->setValue($command, $input);

    $outputProp = (new ReflectionClass(Command::class))->getProperty('output');
    $outputProp->setAccessible(true);
    $outputProp->setValue($command, $output);

    $method = new ReflectionMethod($command, 'failCommand');
    $method->setAccessible(true);
    $exitCode = $method->invoke($command, $code, $message, $meta, $data);

    return [
        'exitCode' => $exitCode,
        'output' => $output->fetch(),
    ];
}

function cleanupDnsResolveTldJsonConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    cleanupDnsResolveTldJsonConfig();
});

afterEach(function (): void {
    cleanupDnsResolveTldJsonConfig();
});

describe('dns:resolve-tld JSON renderer contract', function (): void {
    it('selects JSON renderer with --json and returns discriminated success envelope', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
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
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success'])->toBeArray()
            ->and($payload['success'])->toHaveKey('data');
    });

    it('returns success with status resolved and changed true for new resolve', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
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
            ->and($payload['success']['data']['dns'])->toBe([
                'tld' => 'test',
                'target' => '10.6.0.7',
                'action' => 'resolve',
                'status' => 'resolved',
                'changed' => true,
                'source' => 'local_resolver',
                'resolver_backend' => 'dnsmasq',
            ]);
    });

    it('returns success with status already_resolved and changed false', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
        File::put(storage_path('app/orbit/dnsmasq.d/test.conf'), "address=/.test/10.6.0.7\n");

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
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

    it('returns reset success with target null and status reset', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        File::ensureDirectoryExists(storage_path('app/orbit/dnsmasq.d'));
        File::put(storage_path('app/orbit/dnsmasq.d/test.conf'), "address=/.test/10.6.0.7\n");

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
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
            ->and($payload['success']['data']['dns']['action'])->toBe('reset')
            ->and($payload['success']['data']['dns']['status'])->toBe('reset')
            ->and($payload['success']['data']['dns']['target'])->toBeNull()
            ->and($payload['success']['data']['dns']['changed'])->toBeTrue();
    });

    it('returns already_absent status for idempotent reset', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
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

    it('returns refresh_failed with partial data under error.data', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            'sudo mkdir -p /etc/resolver *' => Process::result(output: '', errorOutput: '', exitCode: 0),
            'brew services restart dnsmasq' => Process::result(output: '', errorOutput: 'dnsmasq not running', exitCode: 1),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_resolver_refresh_failed')
            ->and($payload['error'])->toHaveKey('data')
            ->and($payload['error']['data']['dns']['status'])->toBe('refresh_failed')
            ->and($payload['error']['data']['dns']['changed'])->toBeTrue();
    });

    it('returns validation_failed error with correct metadata', function (): void {
        setupDnsResolveTldJsonControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => '.test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Development TLD must be a single lowercase DNS label without a leading dot.')
            ->and($payload['error']['meta'])->toBe(['field' => 'tld', 'reason' => 'invalid']);
    });

    it('returns destructive_consent_required error with correct metadata', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

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
            ->and($payload['error']['meta']['field'])->toBe('force')
            ->and($payload['error']['meta']['reason'])->toBe('missing');
    });

    it('returns unsupported_platform error with correct metadata', function (): void {
        setupDnsResolveTldJsonControlCaller();

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

    it('returns local_resolver_write_failed error with correct metadata', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            'sudo mkdir -p /etc/resolver *' => Process::result(output: '', errorOutput: 'permission denied', exitCode: 1),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('local_resolver_write_failed')
            ->and($payload['error']['meta']['tld'])->toBe('test')
            ->and($payload['error']['meta']['resolver_backend'])->toBe('dnsmasq');
    });

    it('forces non-interactive mode with --json even in TTY', function (): void {
        setupDnsResolveTldJsonControlCaller();
        setupDnsResolveTldJsonMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
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
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error');
    });
});
