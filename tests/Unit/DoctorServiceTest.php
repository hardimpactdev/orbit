<?php

use App\Models\Environment;
use App\Services\DnsResolverService;
use App\Services\DoctorService;
use App\Services\LaunchpadCli\ConfigurationService;
use App\Services\LaunchpadCli\StatusService;
use App\Services\SshService;

beforeEach(function () {
    $this->sshService = Mockery::mock(SshService::class);
    $this->statusService = Mockery::mock(StatusService::class);
    $this->configService = Mockery::mock(ConfigurationService::class);
    $this->dnsResolverService = Mockery::mock(DnsResolverService::class);

    $this->environment = Environment::create([
        'name' => 'Test Environment',
        'host' => '10.8.0.16',
        'user' => 'launchpad',
        'port' => 22,
        'is_local' => false,
        'is_default' => true,
        'status' => 'active',
        'tld' => 'test',
    ]);

    $this->service = new DoctorService(
        $this->sshService,
        $this->statusService,
        $this->configService,
        $this->dnsResolverService
    );
});

describe('runChecks', function () {
    test('returns all check results with healthy status when all pass', function () {
        // SSH check
        $this->sshService->shouldReceive('execute')
            ->with(Mockery::type(Environment::class), 'echo "connected"', 10)
            ->andReturn(['success' => true, 'output' => 'connected']);

        // CLI installation check
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true, 'version' => '1.0.0', 'path' => '/usr/local/bin/launchpad']);

        // Docker services check
        $this->statusService->shouldReceive('status')
            ->andReturn([
                'success' => true,
                'data' => [
                    'services' => [
                        ['name' => 'caddy', 'status' => 'running'],
                        ['name' => 'dns', 'status' => 'running'],
                    ],
                ],
            ]);

        // API check (sites)
        $this->statusService->shouldReceive('sites')
            ->andReturn([
                'success' => true,
                'data' => ['sites' => [['name' => 'test-site']]],
            ]);

        // Server DNS check - SSH command
        $this->sshService->shouldReceive('execute')
            ->with(
                Mockery::type(Environment::class),
                Mockery::pattern('/getent hosts/'),
                10
            )
            ->andReturn(['success' => true, 'output' => '127.0.0.1 launchpad.test']);

        // Config check
        $this->configService->shouldReceive('getConfig')
            ->andReturn([
                'success' => true,
                'exists' => true,
                'data' => ['tld' => 'test', 'paths' => ['~/projects']],
            ]);

        $result = $this->service->runChecks($this->environment);

        expect($result['success'])->toBeTrue()
            ->and($result['checks'])->toHaveKeys(['ssh', 'cli', 'docker', 'api', 'environment_dns', 'local_dns', 'config'])
            ->and($result['summary']['total'])->toBe(7);
    });

    test('returns unhealthy status when SSH fails', function () {
        // SSH fails
        $this->sshService->shouldReceive('execute')
            ->with(Mockery::type(Environment::class), 'echo "connected"', 10)
            ->andReturn(['success' => false, 'error' => 'Connection refused']);

        // Mock other checks with minimal responses
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => false]);

        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => false, 'error' => 'Cannot connect']);

        $this->sshService->shouldReceive('execute')
            ->andReturn(['success' => false]);

        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => false, 'error' => 'Connection error']);

        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => false, 'error' => 'Cannot read config']);

        $result = $this->service->runChecks($this->environment);

        expect($result['status'])->toBe('unhealthy')
            ->and($result['checks']['ssh']['status'])->toBe('error')
            ->and($result['summary']['errors'])->toBeGreaterThan(0);
    });
});

describe('quickCheck', function () {
    test('only runs SSH and API checks', function () {
        // SSH check
        $this->sshService->shouldReceive('execute')
            ->with(Mockery::type(Environment::class), 'echo "connected"', 10)
            ->andReturn(['success' => true, 'output' => 'connected']);

        // API check
        $this->statusService->shouldReceive('sites')
            ->andReturn([
                'success' => true,
                'data' => ['sites' => []],
            ]);

        $result = $this->service->quickCheck($this->environment);

        expect($result['success'])->toBeTrue()
            ->and($result['status'])->toBe('ok')
            ->and($result['checks'])->toHaveKeys(['ssh', 'api'])
            ->and($result['checks'])->not->toHaveKeys(['cli', 'docker', 'environment_dns', 'local_dns', 'config']);
    });

    test('returns error status when SSH fails', function () {
        $this->sshService->shouldReceive('execute')
            ->andReturn(['success' => false, 'error' => 'Connection refused']);

        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => true, 'data' => ['sites' => []]]);

        $result = $this->service->quickCheck($this->environment);

        expect($result['status'])->toBe('error')
            ->and($result['checks']['ssh']['status'])->toBe('error');
    });
});

describe('fixIssue', function () {
    test('fixes local_dns by calling DnsResolverService', function () {
        $this->dnsResolverService->shouldReceive('updateResolver')
            ->with($this->environment, 'test')
            ->andReturn(['success' => true]);

        $result = $this->service->fixIssue($this->environment, 'local_dns');

        expect($result['success'])->toBeTrue()
            ->and($result['message'])->toContain('Created DNS resolver');
    });

    test('returns error for local_dns when DnsResolverService fails', function () {
        $this->dnsResolverService->shouldReceive('updateResolver')
            ->andReturn(['success' => false, 'error' => 'Permission denied']);

        $result = $this->service->fixIssue($this->environment, 'local_dns');

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toBe('Permission denied');
    });

    test('returns not available for unknown checks', function () {
        $result = $this->service->fixIssue($this->environment, 'unknown_check');

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('No automatic fix available');
    });
});

describe('SSH connectivity check', function () {
    test('passes for local environment without SSH', function () {
        $localEnv = Environment::create([
            'name' => 'Local',
            'host' => 'localhost',
            'user' => 'user',
            'port' => 22,
            'is_local' => true,
            'is_default' => false,
            'status' => 'active',
            'tld' => 'test',
        ]);

        // For local, we need to mock all checks that don't require SSH
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true, 'version' => '1.0.0']);

        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => true, 'data' => ['services' => []]]);

        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => true, 'data' => ['sites' => []]]);

        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => true, 'exists' => true, 'data' => []]);

        // Mock the Process facade for the dig command (local DNS check)
        Illuminate\Support\Facades\Process::fake([
            'dig *' => Illuminate\Support\Facades\Process::result(
                output: '127.0.0.1',
                exitCode: 0
            ),
        ]);

        $result = $this->service->runChecks($localEnv);

        expect($result['checks']['ssh']['status'])->toBe('ok')
            ->and($result['checks']['ssh']['message'])->toContain('Local environment');
    });
});

describe('CLI installation check', function () {
    test('returns error when CLI not installed', function () {
        $this->sshService->shouldReceive('execute')->andReturn(['success' => true, 'output' => 'connected']);
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => false]);
        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => false]);
        $this->sshService->shouldReceive('execute')->andReturn(['success' => false]);
        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => false]);
        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => false]);

        $result = $this->service->runChecks($this->environment);

        expect($result['checks']['cli']['status'])->toBe('error')
            ->and($result['checks']['cli']['message'])->toContain('not installed');
    });

    test('returns version info when CLI installed', function () {
        $this->sshService->shouldReceive('execute')->andReturn(['success' => true, 'output' => 'connected']);
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true, 'version' => '1.2.3', 'path' => '/usr/local/bin/launchpad']);
        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => false]);
        $this->sshService->shouldReceive('execute')->andReturn(['success' => false]);
        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => false]);
        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => false]);

        $result = $this->service->runChecks($this->environment);

        expect($result['checks']['cli']['status'])->toBe('ok')
            ->and($result['checks']['cli']['details']['version'])->toBe('1.2.3');
    });
});

describe('Docker services check', function () {
    test('returns warning when some services are stopped', function () {
        $this->sshService->shouldReceive('execute')->andReturn(['success' => true, 'output' => 'connected']);
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true]);
        $this->statusService->shouldReceive('status')
            ->andReturn([
                'success' => true,
                'data' => [
                    'services' => [
                        ['name' => 'caddy', 'status' => 'running'],
                        ['name' => 'dns', 'status' => 'stopped'],
                    ],
                ],
            ]);
        $this->sshService->shouldReceive('execute')->andReturn(['success' => false]);
        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => false]);
        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => false]);

        $result = $this->service->runChecks($this->environment);

        expect($result['checks']['docker']['status'])->toBe('warning')
            ->and($result['checks']['docker']['message'])->toContain('1/2');
    });
});

describe('API connectivity check', function () {
    test('returns site count when API responds', function () {
        $this->sshService->shouldReceive('execute')->andReturn(['success' => true, 'output' => 'connected']);
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true]);
        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => false]);
        $this->sshService->shouldReceive('execute')->andReturn(['success' => false]);
        $this->statusService->shouldReceive('sites')
            ->andReturn([
                'success' => true,
                'data' => ['sites' => [['name' => 'site1'], ['name' => 'site2']]],
            ]);
        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => false]);

        $result = $this->service->runChecks($this->environment);

        expect($result['checks']['api']['status'])->toBe('ok')
            ->and($result['checks']['api']['message'])->toContain('2 sites');
    });

    test('returns error for connection errors', function () {
        $this->sshService->shouldReceive('execute')->andReturn(['success' => true, 'output' => 'connected']);
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true]);
        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => false]);
        $this->sshService->shouldReceive('execute')->andReturn(['success' => false]);
        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => false, 'error' => 'cURL error: Connection refused']);
        $this->configService->shouldReceive('getConfig')
            ->andReturn(['success' => false]);

        $result = $this->service->runChecks($this->environment);

        expect($result['checks']['api']['status'])->toBe('error')
            ->and($result['checks']['api']['message'])->toContain('unreachable');
    });
});

describe('config check', function () {
    test('returns warning when config does not exist', function () {
        $this->sshService->shouldReceive('execute')->andReturn(['success' => true, 'output' => 'connected']);
        $this->statusService->shouldReceive('checkInstallation')
            ->andReturn(['installed' => true]);
        $this->statusService->shouldReceive('status')
            ->andReturn(['success' => false]);
        $this->sshService->shouldReceive('execute')->andReturn(['success' => false]);
        $this->statusService->shouldReceive('sites')
            ->andReturn(['success' => false]);
        $this->configService->shouldReceive('getConfig')
            ->andReturn([
                'success' => true,
                'exists' => false,
                'data' => ['tld' => 'test'],
            ]);

        $result = $this->service->runChecks($this->environment);

        expect($result['checks']['config']['status'])->toBe('warning')
            ->and($result['checks']['config']['message'])->toContain('does not exist');
    });
});
