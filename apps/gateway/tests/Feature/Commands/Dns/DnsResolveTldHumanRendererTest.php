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
function dnsResolveTldHumanRow(array $overrides = []): array
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

function setupDnsResolveTldHumanControlCaller(): void
{
    DB::table('nodes')->insert(dnsResolveTldHumanRow());
}

function setupDnsResolveTldHumanMacosResolver(): LocalResolver
{
    $resolver = app(LocalResolver::class);
    $resolver->setPlatform('macos');

    return $resolver;
}

function invokeDnsResolveTldFailCommandHuman(string $code, string $message, array $meta, ?array $data = null): array
{
    $command = new DnsResolveTldCommand;
    $command->setLaravel(app());
    $input = new ArrayInput([
        'tld' => 'dummy',
        'target' => '10.6.0.7',
    ]);
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

function cleanupDnsResolveTldHumanConfig(): void
{
    $configDir = storage_path('app/orbit/dnsmasq.d');

    if (File::isDirectory($configDir)) {
        File::deleteDirectory($configDir);
    }
}

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    cleanupDnsResolveTldHumanConfig();
});

afterEach(function (): void {
    cleanupDnsResolveTldHumanConfig();
});

describe('dns:resolve-tld human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders resolve progress tree', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Configuring Local DNS')
            ->and($output)->toContain('○  Validate .test')
            ->and($output)->toContain('○  Write resolver override')
            ->and($output)->toContain('○  Refresh resolver')
            ->and($output)->toContain('└');
    });

    it('renders decorated resolve progress tree with ansi state dots', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $output = new BufferedOutput(decorated: true);
        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ], $output);
        $text = $output->fetch();

        expect($exitCode)->toBe(0);
        expect($text)->toContain("\e[38;5;242m┌\e[39m  \e[97mConfiguring Local DNS\e[39m")
            ->and($text)->toContain("\e[32m●\e[39m")
            ->and($text)->toContain('Working...')
            ->and($text)->toContain('.test resolves to 10.6.0.7.');
    });

    it('renders already-resolved progress tree', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Configuring Local DNS')
            ->and($output)->toContain('○  Check resolver override')
            ->and($output)->toContain('.test already resolves to 10.6.0.7');
    });

    it('renders resolved success prose', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('.test resolves to 10.6.0.7.');
    });

    it('renders already-resolved success prose', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('.test already resolves to 10.6.0.7.');
    });

    it('renders reset progress tree', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Resetting Local DNS')
            ->and($output)->toContain('○  Remove resolver override')
            ->and($output)->toContain('○  Refresh resolver')
            ->and($output)->toContain('.test resolver override removed');
    });

    it('renders already-absent progress tree', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Resetting Local DNS')
            ->and($output)->toContain('○  Check resolver override')
            ->and($output)->toContain('.test resolver override already absent');
    });

    it('renders reset success prose', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('.test resolver override removed.');
    });

    it('renders already-absent success prose', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('.test resolver override already absent.');
    });

    it('renders validation failure prose error for invalid TLD', function (): void {
        setupDnsResolveTldHumanControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => '.test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Development TLD must be a single lowercase DNS label without a leading dot.');
    });

    it('renders validation failure prose error for invalid target', function (): void {
        setupDnsResolveTldHumanControlCaller();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => 'not-an-ip',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Target must be an IPv4 or IPv6 address.');
    });

    it('renders destructive-consent-cancellation prose error', function (): void {
        $result = invokeDnsResolveTldFailCommandHuman(
            code: 'destructive_consent_required',
            message: 'Reset cancelled. No local resolver changes were made.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Reset cancelled. No local resolver changes were made.');
    });

    it('renders unsupported-platform prose error', function (): void {
        setupDnsResolveTldHumanControlCaller();

        $resolver = app(LocalResolver::class);
        $resolver->setPlatform('linux');

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('This platform does not support automatic local DNS resolver configuration.');
    });

    it('renders resolver-write-failed prose error', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            'sudo mkdir -p /etc/resolver *' => Process::result(output: '', errorOutput: 'permission denied', exitCode: 1),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Failed to update local DNS resolver configuration.');
    });

    it('renders resolver-refresh-failed prose error', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

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
        ]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Local DNS resolver configuration changed, but the resolver could not be refreshed.');
    });

    it('absence of JSON envelopes in human mode', function (): void {
        setupDnsResolveTldHumanControlCaller();
        setupDnsResolveTldHumanMacosResolver();

        Process::fake([
            'which dnsmasq' => Process::result(output: fakeHomebrewPrefix().'/bin/dnsmasq', errorOutput: '', exitCode: 0),
            'brew --prefix' => Process::result(output: fakeHomebrewPrefix(), errorOutput: '', exitCode: 0),
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $exitCode = Artisan::call('dns:resolve-tld', [
            'tld' => 'test',
            'target' => '10.6.0.7',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"')
            ->and($output)->not->toContain('"code"');
    });
});
