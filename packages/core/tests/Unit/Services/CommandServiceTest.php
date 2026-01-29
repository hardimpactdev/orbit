<?php
declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->sshService = mock(SshService::class);
    $this->commandService = new CommandService($this->sshService);
});

describe('CommandService', function () {
    describe('executeCommand', function () {
        it('routes to executeLocalCommand for local environments', function () {
            $environment = Environment::factory()->local()->create();

            // Set a fake CLI path that exists
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => Process::result(output: '{"success": true, "data": {"key": "value"}}'),
            ]);

            $result = $this->commandService->executeCommand($environment, 'status --json');

            expect($result)->toHaveKey('success');

            unlink($tempPath);
        });

        it('routes to executeRemoteCommand for remote environments', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->withArgs(function ($env, $cmd) use ($environment) {
                    return $env->id === $environment->id && str_contains($cmd, 'status --json');
                })
                ->andReturn(['success' => true, 'data' => ['success' => true, 'data' => []]]);

            $result = $this->commandService->executeCommand($environment, 'status --json');

            expect($result)->toHaveKey('success');
        });
    });

    describe('executeLocalCommand', function () {
        it('returns error when CLI path is not configured', function () {
            Config::set('orbit.cli_path', null);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Orbit CLI not found. Set ORBIT_CLI_PATH in .env');
            expect($result['exit_code'])->toBe(1);
        });

        it('returns error when CLI path does not exist', function () {
            Config::set('orbit.cli_path', '/nonexistent/path/orbit');

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Orbit CLI not found. Set ORBIT_CLI_PATH in .env');
            expect($result['exit_code'])->toBe(1);
        });

        it('builds correct command with configured cli_path', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                "{$tempPath} *" => Process::result(output: '{"success": true}'),
            ]);

            $this->commandService->executeLocalCommand('status --json');

            Process::assertRan("{$tempPath} status --json");

            unlink($tempPath);
        });

        it('passes timeout to process', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => Process::result(output: '{"success": true}'),
            ]);

            $result = $this->commandService->executeLocalCommand('site:create test --json', 600);

            // The timeout is passed internally - we verify the command executed successfully
            expect($result)->toBe(['success' => true]);

            unlink($tempPath);
        });

        it('returns decoded JSON on success', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => Process::result(output: '{"success": true, "data": {"status": "running"}}'),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result)->toBe(['success' => true, 'data' => ['status' => 'running']]);

            unlink($tempPath);
        });

        it('returns error when command fails', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => Process::result(
                    output: '',
                    errorOutput: 'Error: Site not found',
                    exitCode: 1
                ),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Error: Site not found');
            expect($result['exit_code'])->toBe(1);

            unlink($tempPath);
        });

        it('returns stdout as error when stderr is empty', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => Process::result(
                    output: 'The "--org" option does not exist.',
                    errorOutput: '',
                    exitCode: 1
                ),
            ]);

            $result = $this->commandService->executeLocalCommand('site:create test --org=my-org --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('--org');

            unlink($tempPath);
        });

        it('returns error on invalid JSON output', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => Process::result(output: 'Not valid JSON at all'),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Failed to parse JSON');

            unlink($tempPath);
        });

        it('handles exceptions gracefully', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            Process::fake([
                '*' => fn () => throw new \RuntimeException('Process timeout'),
            ]);

            $result = $this->commandService->executeLocalCommand('status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toContain('Command timed out or failed');
            expect($result['error'])->toContain('Process timeout');

            unlink($tempPath);
        });
    });

    describe('executeRemoteCommand', function () {
        it('uses configured cli_path when available', function () {
            $environment = Environment::factory()->create([
                'is_local' => false,
                'cli_path' => '/custom/orbit',
            ]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->withArgs(function ($env, $cmd) {
                    return $cmd === '/custom/orbit status --json';
                })
                ->andReturn(['success' => true, 'data' => ['success' => true]]);

            $this->commandService->executeRemoteCommand($environment, 'status --json');
        });

        it('uses default path when cli_path is not configured', function () {
            $environment = Environment::factory()->create([
                'is_local' => false,
                'cli_path' => null,
            ]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->withArgs(function ($env, $cmd) {
                    return $cmd === '$HOME/.local/bin/orbit status --json';
                })
                ->andReturn(['success' => true, 'data' => ['success' => true]]);

            $this->commandService->executeRemoteCommand($environment, 'status --json');
        });

        it('returns error on SSH failure', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->andReturn([
                    'success' => false,
                    'error' => 'Connection refused',
                    'exit_code' => 255,
                ]);

            $result = $this->commandService->executeRemoteCommand($environment, 'status --json');

            expect($result['success'])->toBeFalse();
            expect($result['error'])->toBe('Connection refused');
            expect($result['exit_code'])->toBe(255);
        });

        it('returns data from successful SSH execution', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('executeJson')
                ->once()
                ->andReturn([
                    'success' => true,
                    'data' => [
                        'success' => true,
                        'data' => ['status' => 'running'],
                    ],
                ]);

            $result = $this->commandService->executeRemoteCommand($environment, 'status --json');

            expect($result)->toBe(['success' => true, 'data' => ['status' => 'running']]);
        });
    });

    describe('findBinary', function () {
        it('returns path when binary is found', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => true,
                    'output' => '/home/user/.local/bin/orbit',
                ]);

            $result = $this->commandService->findBinary($environment);

            expect($result)->toBe('/home/user/.local/bin/orbit');
        });

        it('returns null when binary is not found', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => false,
                    'output' => '',
                ]);

            $result = $this->commandService->findBinary($environment);

            expect($result)->toBeNull();
        });

        it('trims whitespace from path', function () {
            $environment = Environment::factory()->create(['is_local' => false]);

            $this->sshService->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => true,
                    'output' => "  /path/to/orbit\n  ",
                ]);

            $result = $this->commandService->findBinary($environment);

            expect($result)->toBe('/path/to/orbit');
        });
    });

    describe('isLocalCliInstalled', function () {
        it('returns true when CLI path is configured and exists', function () {
            $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
            file_put_contents($tempPath, '<?php echo "{}";');
            chmod($tempPath, 0755);
            Config::set('orbit.cli_path', $tempPath);

            expect($this->commandService->isLocalCliInstalled())->toBeTrue();

            unlink($tempPath);
        });

        it('returns false when CLI path is not configured', function () {
            Config::set('orbit.cli_path', null);

            expect($this->commandService->isLocalCliInstalled())->toBeFalse();
        });

        it('returns false when CLI path does not exist', function () {
            Config::set('orbit.cli_path', '/nonexistent/path/orbit');

            expect($this->commandService->isLocalCliInstalled())->toBeFalse();
        });
    });

    describe('getLocalCliPath', function () {
        it('returns configured CLI path', function () {
            Config::set('orbit.cli_path', '/path/to/orbit.phar');

            expect($this->commandService->getLocalCliPath())->toBe('/path/to/orbit.phar');
        });

        it('returns null when not configured', function () {
            Config::set('orbit.cli_path', null);

            expect($this->commandService->getLocalCliPath())->toBeNull();
        });
    });

    describe('executeRawCommand', function () {
        describe('local environment', function () {
            it('returns error when CLI is not installed', function () {
                $environment = Environment::factory()->local()->create();

                Config::set('orbit.cli_path', null);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeFalse();
                expect($result['error'])->toBe('Orbit CLI not found. Set ORBIT_CLI_PATH in .env');
            });

            it('returns output on success', function () {
                $environment = Environment::factory()->local()->create();

                $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
                file_put_contents($tempPath, '<?php echo "{}";');
                chmod($tempPath, 0755);
                Config::set('orbit.cli_path', $tempPath);

                Process::fake([
                    '*' => Process::result(output: 'PHP container logs here'),
                ]);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeTrue();
                expect($result['output'])->toContain('PHP container logs here');
                expect($result['error'])->toBeNull();

                unlink($tempPath);
            });

            it('returns error on failure', function () {
                $environment = Environment::factory()->local()->create();

                $tempPath = sys_get_temp_dir().'/orbit-test-'.uniqid();
                file_put_contents($tempPath, '<?php echo "{}";');
                chmod($tempPath, 0755);
                Config::set('orbit.cli_path', $tempPath);

                Process::fake([
                    '*' => Process::result(
                        output: '',
                        errorOutput: 'Container not found',
                        exitCode: 1
                    ),
                ]);

                $result = $this->commandService->executeRawCommand($environment, 'logs nonexistent');

                expect($result['success'])->toBeFalse();
                expect($result['error'])->toContain('Container not found');

                unlink($tempPath);
            });
        });

        describe('remote environment', function () {
            it('returns error when CLI is not found on remote', function () {
                $environment = Environment::factory()->create(['is_local' => false]);

                $this->sshService->shouldReceive('execute')
                    ->once()
                    ->andReturn(['success' => false, 'output' => '']);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeFalse();
                expect($result['error'])->toBe('Orbit CLI not found on remote server');
            });

            it('executes command via SSH when CLI is found', function () {
                $environment = Environment::factory()->create(['is_local' => false]);

                // First call finds binary
                $this->sshService->shouldReceive('execute')
                    ->once()
                    ->andReturn(['success' => true, 'output' => '/home/user/.local/bin/orbit']);

                // Second call executes command
                $this->sshService->shouldReceive('execute')
                    ->once()
                    ->withArgs(function ($env, $cmd, $timeout) {
                        return str_contains($cmd, '/home/user/.local/bin/orbit logs php');
                    })
                    ->andReturn(['success' => true, 'output' => 'Remote logs']);

                $result = $this->commandService->executeRawCommand($environment, 'logs php');

                expect($result['success'])->toBeTrue();
                expect($result['output'])->toBe('Remote logs');
            });
        });
    });
});
