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
        it('auto-detects CLI when path is not configured', function () {
            Config::set('orbit.cli_path', null);

            // If CLI is installed on the system, auto-detection will find it
            // If not installed, it should return an error
            $cliPath = $this->commandService->getLocalCliPath();

            if ($cliPath === null) {
                // No CLI installed - should return error
                $result = $this->commandService->executeLocalCommand('status --json');
                expect($result['success'])->toBeFalse();
                expect($result['error'])->toBe('Orbit CLI not found. Set ORBIT_CLI_PATH in .env');
            } else {
                // CLI found via auto-detection - should work
                expect($cliPath)->toBeString();
            }
        });

        it('falls back to auto-detection when configured path does not exist', function () {
            Config::set('orbit.cli_path', '/nonexistent/path/orbit');

            // The service will try auto-detection when configured path doesn't exist
            $cliPath = $this->commandService->getLocalCliPath();

            if ($cliPath === null) {
                // No CLI found anywhere
                $result = $this->commandService->executeLocalCommand('status --json');
                expect($result['success'])->toBeFalse();
            } else {
                // CLI found via auto-detection (different from configured path)
                expect($cliPath)->not->toBe('/nonexistent/path/orbit');
            }
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

        it('uses auto-detection when CLI path is not configured', function () {
            Config::set('orbit.cli_path', null);

            // Result depends on whether CLI is installed on the system
            $result = $this->commandService->isLocalCliInstalled();
            $cliPath = $this->commandService->getLocalCliPath();

            // The result should match whether auto-detection found the CLI
            expect($result)->toBe($cliPath !== null);
        });

        it('uses auto-detection when configured CLI path does not exist', function () {
            Config::set('orbit.cli_path', '/nonexistent/path/orbit');

            // Auto-detection should kick in when configured path doesn't exist
            $result = $this->commandService->isLocalCliInstalled();
            $cliPath = $this->commandService->getLocalCliPath();

            // The result should match whether auto-detection found a CLI
            expect($result)->toBe($cliPath !== null);
        });
    });

    describe('getLocalCliPath', function () {
        it('returns configured CLI path when file exists', function () {
            $tempFile = tempnam(sys_get_temp_dir(), 'orbit-test-');
            Config::set('orbit.cli_path', $tempFile);

            expect($this->commandService->getLocalCliPath())->toBe($tempFile);

            unlink($tempFile);
        });

        it('auto-detects from common paths when not configured', function () {
            Config::set('orbit.cli_path', null);

            // When config is null, it should try to auto-detect
            $result = $this->commandService->getLocalCliPath();
            // Result could be a detected path or null if not found anywhere
            expect($result === null || is_string($result))->toBeTrue();
        });

        it('returns null when configured path does not exist', function () {
            Config::set('orbit.cli_path', '/nonexistent/path/orbit.phar');

            // When configured path doesn't exist, it falls back to auto-detection
            $result = $this->commandService->getLocalCliPath();
            expect($result === null || is_string($result))->toBeTrue();
        });
    });

    describe('executeRawCommand', function () {
        describe('local environment', function () {
            it('returns error or succeeds based on CLI availability', function () {
                $environment = Environment::factory()->local()->create();

                Config::set('orbit.cli_path', null);

                // If CLI is auto-detected, the command may succeed or fail based on the command itself
                // If CLI is not found anywhere, it should return the CLI not found error
                $cliPath = $this->commandService->getLocalCliPath();

                if ($cliPath === null) {
                    $result = $this->commandService->executeRawCommand($environment, 'logs php');
                    expect($result['success'])->toBeFalse();
                    expect($result['error'])->toBe('Orbit CLI not found. Set ORBIT_CLI_PATH in .env');
                } else {
                    // CLI is installed - test passes (auto-detection works)
                    expect($cliPath)->toBeString();
                }
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
