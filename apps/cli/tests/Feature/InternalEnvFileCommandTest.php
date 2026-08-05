<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @mago-expect lint:halstead
 * @mago-expect lint:cyclomatic-complexity
 */
describe('internal env-file command', function (): void {
    beforeEach(function (): void {
        configureEnvFileOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects invalid json payloads after token validation', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand([
            '--operation-token' => envFileSignedOperationToken(),
            '--json' => true,
        ], 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('validation_failed', 'Env file payload is invalid.'));
    });

    it('rejects non-env paths outside managed roots', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => '/etc/passwd',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Env file path is invalid.');
    });

    it('accepts Orbit-managed production app env paths', function (): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => '/home/mealou-production/app/.env',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('env_file.not_found');
    });

    it('writes production app env files as their owning runtime user via stage chmod rename', function (): void {
        Process::fake([
            '*' => Process::result(),
        ]);

        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'write',
                'path' => '/home/mealou-production/app/.env',
                'contents' => "DB_JOURNAL_MODE=WAL\n",
                'runtime_user' => 'mealou-production',
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)->toBe(0)->and($output)->toContain('"bytes":20');

        Process::assertRan(function (PendingProcess $process): bool {
            if (! is_array($process->command) || ($process->command[0] ?? null) !== 'sudo') {
                return false;
            }

            $joined = implode(' ', $process->command);
            $script = (string) ($process->command[array_key_last($process->command)] ?? '');

            return (
                str_contains($joined, '-u mealou-production')
                && str_contains($joined, 'sh')
                && ! str_contains($joined, 'tee')
                && str_starts_with($script, 'set -eu')
                && ! str_contains($script, 'pipefail')
                && str_contains($script, 'trap')
                && str_contains($script, 'mv -f')
                && str_contains($script, '[ -L ')
                && $process->input === "DB_JOURNAL_MODE=WAL\n"
            );
        });
    });

    it('executes the runtime-user publish script under real POSIX sh with trap cleanup and symlink rejection', function (): void {
        $directory = sys_get_temp_dir().'/orbit-env-posix-'.bin2hex(random_bytes(4));
        mkdir($directory, permissions: 0o755);
        $path = $directory.'/.env';
        $temporary = $directory.'/.env.tmp.'.bin2hex(random_bytes(4));
        file_put_contents($path, data: "OLD=1\n");
        chmod($path, permissions: 0o640);

        try {
            $script = \App\Services\EnvFiles\RuntimeUserEnvFileWriter::publishScript(
                temporary: $temporary,
                path: $path,
                mode: 0o640,
            );

            expect($script)
                ->toStartWith('set -eu')
                ->not
                ->toContain('pipefail')
                ->toContain('trap')
                ->toContain('[ -L ');

            $process = proc_open(
                ['sh', '-c', $script],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );

            expect($process)->not->toBeFalse();

            fwrite($pipes[0], "NEW=1\nKEEP=yes\n");
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            expect($exitCode)
                ->toBe(0, "stdout={$stdout} stderr={$stderr}")
                ->and(file_get_contents($path))
                ->toBe("NEW=1\nKEEP=yes\n")
                ->and(fileperms($path) & 0o777)
                ->toBe(0o640)
                ->and(is_file($temporary))
                ->toBeFalse();

            $outside = $directory.'/outside.env';
            file_put_contents($outside, data: "SECRET=1\n");
            unlink($path);
            symlink($outside, $path);
            $symlinkTemporary = $directory.'/.env.tmp.'.bin2hex(random_bytes(4));
            $symlinkScript = \App\Services\EnvFiles\RuntimeUserEnvFileWriter::publishScript(
                temporary: $symlinkTemporary,
                path: $path,
                mode: 0o600,
            );

            $symlinkProcess = proc_open(
                ['sh', '-c', $symlinkScript],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $symlinkPipes,
            );

            expect($symlinkProcess)->not->toBeFalse();

            fwrite($symlinkPipes[0], "SHOULD_NOT_WRITE=1\n");
            fclose($symlinkPipes[0]);
            stream_get_contents($symlinkPipes[1]);
            stream_get_contents($symlinkPipes[2]);
            fclose($symlinkPipes[1]);
            fclose($symlinkPipes[2]);
            $symlinkExit = proc_close($symlinkProcess);

            expect($symlinkExit)
                ->not
                ->toBe(0)
                ->and(is_link($path))
                ->toBeTrue()
                ->and(file_get_contents($outside))
                ->toBe("SECRET=1\n")
                ->and(is_file($symlinkTemporary))
                ->toBeFalse();
        } finally {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            env_file_unlink_glob($directory.'/.env.tmp.*');
            if (is_file($directory.'/outside.env')) {
                unlink($directory.'/outside.env');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    });

    it('rejects a production env runtime user that does not own the app path', function (): void {
        Process::fake();

        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'write',
                'path' => '/home/mealou-production/app/.env',
                'contents' => "DB_JOURNAL_MODE=WAL\n",
                'runtime_user' => 'another-app',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['meta']['field'] ?? null)
            ->toBe('runtime_user');

        Process::assertNothingRan();
    });

    it('keeps production env access bounded to the exact app root', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        '/home/mealou-production/.env',
        '/home/mealou-production/app/config/.env',
        '/home/mealou-production/app/../.env',
    ]);

    it('accepts Orbit-managed development app env paths', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('env_file.not_found');
    })->with([
        'linux app-dev' => '/home/orbit-test-user/apps/mealou-env-test/.env',
        'macOS app-dev' => '/Users/orbit-test-user/apps/mealou-env-test/.env',
    ]);

    it('accepts Orbit-managed development worktree env paths', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('env_file.not_found');
    })->with([
        'linux development worktree' => '/home/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/.env',
        'macOS development worktree' => '/Users/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/.env',
    ]);

    it('keeps development env access bounded to the exact app root', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        '/home/nckrtl/mealou/.env',
        '/home/nckrtl/apps/mealou/config/.env',
        '/home/nckrtl/apps/mealou/../.env',
        '/Users/nckrtl/mealou/.env',
        '/Users/nckrtl/apps/mealou/config/.env',
        '/Users/nckrtl/apps/mealou/../.env',
    ]);

    it('rejects unsafe or non-workspace development worktree env paths', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        'nested worktree path' => '/home/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/config/.env',
        'traversal after worktrees' => '/home/orbit-test-user/apps/mealou-env-test/.worktrees/../.env',
        'dot segment' => '/home/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/./.env',
        'extra nested workspace' => '/home/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/nested/.env',
        'alternate filename' => '/home/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/.env.local',
        'arbitrary root' => '/var/tmp/apps/mealou-env-test/.worktrees/feature-mail/.env',
        'macOS nested' => '/Users/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/config/.env',
        'macOS traversal' => '/Users/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/../.env',
        'NUL rejected' => "/home/orbit-test-user/apps/mealou-env-test/.worktrees/feature-mail/.env\0",
        'relative path' => 'apps/mealou-env-test/.worktrees/feature-mail/.env',
    ]);

    it('accepts registered Codex worktree workspace env paths', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('env_file.not_found');
    })->with([
        'linux codex worktree' => '/home/nckrtl/.codex/worktrees/9106/dngdmt/.env',
        'macOS codex worktree' => '/Users/nckrtl/.codex/worktrees/a59f/happie/.env',
    ]);

    it('rejects unsafe or non-workspace Codex env paths', function (string $path): void {
        [$exitCode, $output] = runInternalEnvFileCommand(
            [
                '--operation-token' => envFileSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'read',
                'path' => $path,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        '/home/nckrtl/.codex/worktrees/9106/../.env',
        '/home/nckrtl/.codex/worktrees/9106/dngdmt/config/.env',
        '/home/nckrtl/.codex/.env',
        '/home/nckrtl/.env',
        '/Users/nckrtl/.codex/worktrees/a59f/happie/../.env',
        '/Users/nckrtl/.codex/worktrees/a59f/happie/config/.env',
        '/etc/passwd',
        '/home/nckrtl/.codex/worktrees/9106/dngdmt/.env.bak',
    ]);

    it('reads and writes a real Codex worktree env file', function (): void {
        $workspace = make_env_file_codex_worktree_workspace();
        $envPath = "{$workspace}/.env";
        file_put_contents($envPath, data: "APP_KEY=base\n");

        try {
            [$readExit, $readOutput] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'read',
                    'path' => $envPath,
                ], JSON_THROW_ON_ERROR),
            );

            expect($readExit)
                ->toBe(0)
                ->and($readOutput)
                ->toContain('"contents":"APP_KEY=base\\n"');

            [$writeExit, $writeOutput] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'write',
                    'path' => $envPath,
                    'contents' => "APP_KEY=updated\n",
                ], JSON_THROW_ON_ERROR),
            );

            expect($writeExit)
                ->toBe(0)
                ->and($writeOutput)
                ->toContain('"bytes":16')
                ->and(file_get_contents($envPath))
                ->toBe("APP_KEY=updated\n");
        } finally {
            delete_env_file_codex_worktree_workspace($workspace);
        }
    });

    it('rejects a Codex worktree path whose .env is a symlink escape', function (): void {
        $workspace = make_env_file_codex_worktree_workspace();
        $envPath = "{$workspace}/.env";
        $outside = sys_get_temp_dir().'/orbit-env-escape-'.bin2hex(random_bytes(4));
        file_put_contents($outside, data: "SECRET=1\n");
        symlink($outside, $envPath);

        try {
            [$exitCode, $output] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'read',
                    'path' => $envPath,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($payload['error']['code'] ?? null)
                ->toBe('validation_failed');
        } finally {
            if (is_link($envPath) || is_file($envPath)) {
                unlink($envPath);
            }
            if (is_file($outside)) {
                unlink($outside);
            }
            delete_env_file_codex_worktree_workspace($workspace);
        }
    });

    it('rejects a Codex worktree path whose parent directory symlink escapes the allowlist', function (): void {
        $workspace = make_env_file_codex_worktree_workspace();
        $envPath = "{$workspace}/.env";
        $outside = sys_get_temp_dir().'/orbit-env-parent-escape-'.bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents("{$outside}/.env", data: "SECRET=1\n");

        // Replace the leaf project directory with a symlink to an outside directory.
        rmdir($workspace);
        symlink($outside, $workspace);

        try {
            [$exitCode, $output] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'read',
                    'path' => $envPath,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($payload['error']['code'] ?? null)
                ->toBe('validation_failed');
        } finally {
            if (is_link($workspace)) {
                unlink($workspace);
            }
            if (is_file("{$outside}/.env")) {
                unlink("{$outside}/.env");
            }
            if (is_dir($outside)) {
                rmdir($outside);
            }
            // Parent worktree id directory may still exist under ~/.codex/worktrees.
            $parent = dirname($workspace);
            $grandparent = dirname($parent);
            $parent = dirname($workspace);
            if (is_dir($parent)) {
                rmdir_if_empty($parent);
            }
        }
    });

    it('atomically writes development worktree env files while preserving mode and unrelated content', function (): void {
        $workspace = make_env_file_development_worktree_workspace();
        $envPath = "{$workspace}/.env";
        file_put_contents($envPath, data: "APP_KEY=base\nKEEP_ME=yes\n");
        chmod($envPath, permissions: 0o640);

        try {
            [$writeExit, $writeOutput] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'write',
                    'path' => $envPath,
                    'contents' => "APP_KEY=updated\nKEEP_ME=yes\nNEW_KEY=1\n",
                ], JSON_THROW_ON_ERROR),
            );

            expect($writeExit)
                ->toBe(0)
                ->and($writeOutput)
                ->toContain('"bytes":')
                ->and(file_get_contents($envPath))
                ->toBe("APP_KEY=updated\nKEEP_ME=yes\nNEW_KEY=1\n")
                ->and(fileperms($envPath) & 0o777)
                ->toBe(0o640);

            $secondContents = "APP_KEY=updated\nKEEP_ME=yes\nNEW_KEY=1\n";

            [$secondExit] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'write',
                    'path' => $envPath,
                    'contents' => $secondContents,
                ], JSON_THROW_ON_ERROR),
            );

            expect($secondExit)
                ->toBe(0)
                ->and(file_get_contents($envPath))
                ->toBe($secondContents)
                ->and(fileperms($envPath) & 0o777)
                ->toBe(0o640);
        } finally {
            delete_env_file_development_worktree_workspace($workspace);
        }
    });

    it('rejects a development worktree path whose .env is a symlink escape', function (): void {
        $workspace = make_env_file_development_worktree_workspace();
        $envPath = "{$workspace}/.env";
        $outside = sys_get_temp_dir().'/orbit-dev-worktree-env-escape-'.bin2hex(random_bytes(4));
        file_put_contents($outside, data: "SECRET=1\n");
        symlink($outside, $envPath);

        try {
            [$exitCode, $output] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'read',
                    'path' => $envPath,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($payload['error']['code'] ?? null)
                ->toBe('validation_failed');
        } finally {
            if (is_link($envPath) || is_file($envPath)) {
                unlink($envPath);
            }
            if (is_file($outside)) {
                unlink($outside);
            }
            delete_env_file_development_worktree_workspace($workspace);
        }
    });
});

describe('internal env-file non-regular target publication', function (): void {
    beforeEach(function (): void {
        configureEnvFileOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects publish when an existing .env target is a directory rather than a regular file', function (): void {
        $directory = sys_get_temp_dir().'/orbit-env-posix-dir-'.bin2hex(random_bytes(4));
        mkdir($directory, permissions: 0o755);
        $path = $directory.'/.env';
        mkdir($path, permissions: 0o755);
        $temporary = $directory.'/.env.tmp.'.bin2hex(random_bytes(4));

        try {
            $script = \App\Services\EnvFiles\RuntimeUserEnvFileWriter::publishScript(
                temporary: $temporary,
                path: $path,
                mode: 0o600,
            );

            expect($script)->toContain('[ ! -f ');

            $exitCode = env_file_run_posix_publish_script($script, "SHOULD_NOT_PUBLISH=1\n");
            $movedInside = env_file_glob_entries($path.'/*');

            expect($exitCode)
                ->not
                ->toBe(0)
                ->and(is_dir($path))
                ->toBeTrue()
                ->and($movedInside)
                ->toBeEmpty()
                ->and(is_file($temporary))
                ->toBeFalse()
                ->and(file_exists($path.'/SHOULD_NOT_PUBLISH'))
                ->toBeFalse();
        } finally {
            env_file_remove_directory_if_present($path);
            if (is_file($temporary)) {
                unlink($temporary);
            }
            env_file_unlink_glob($directory.'/.env.tmp.*');
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    });

    it('rejects a direct write when an existing managed .env target is a directory', function (): void {
        $workspace = make_env_file_development_worktree_workspace();
        $envPath = "{$workspace}/.env";
        mkdir($envPath, permissions: 0o755);

        try {
            [$exitCode, $output] = runInternalEnvFileCommand(
                [
                    '--operation-token' => envFileSignedOperationToken(),
                    '--json' => true,
                ],
                json_encode([
                    'action' => 'write',
                    'path' => $envPath,
                    'contents' => "APP_KEY=should-fail\n",
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
            $movedInside = env_file_glob_entries($envPath.'/*');

            expect($exitCode)
                ->toBe(1)
                ->and($payload['error']['code'] ?? null)
                ->toBe('validation_failed')
                ->and(is_dir($envPath))
                ->toBeTrue()
                ->and($movedInside)
                ->toBeEmpty();
        } finally {
            env_file_remove_directory_if_present($envPath);
            delete_env_file_development_worktree_workspace($workspace);
        }
    });
});

/**
 * @return list<string>
 */
function env_file_glob_entries(string $pattern): array
{
    $entries = glob($pattern);

    return $entries === false ? [] : $entries;
}

function env_file_unlink_glob(string $pattern): void
{
    foreach (env_file_glob_entries($pattern) as $path) {
        if (! (is_file($path) || is_link($path))) {
            continue;
        }

        unlink($path);
    }
}

function env_file_remove_directory_if_present(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    env_file_unlink_glob($path.'/*');
    rmdir($path);
}

function env_file_run_posix_publish_script(string $script, string $stdin): int
{
    $process = proc_open(
        ['sh', '-c', $script],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    expect($process)->not->toBeFalse();

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process);
}

function env_file_codex_home_root(): string
{
    $home = getenv('HOME');

    if (! is_string($home) || $home === '') {
        $home = (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '');
    }

    if (
        ! str_starts_with($home, '/Users/')
        && ! str_starts_with($home, '/home/')
    ) {
        test()->markTestSkipped('Codex worktree env tests require a /Users or /home HOME.');
    }

    return rtrim($home, characters: '/');
}

function make_env_file_codex_worktree_workspace(): string
{
    $id = bin2hex(random_bytes(3));
    $workspace = env_file_codex_home_root()."/.codex/worktrees/{$id}/envtest";
    mkdir($workspace, permissions: 0o755, recursive: true);

    return $workspace;
}

function make_env_file_development_worktree_workspace(): string
{
    $app = 'mealou-env-test-'.bin2hex(random_bytes(3));
    $workspaceName = 'feature-mail';
    $workspace = env_file_codex_home_root()."/apps/{$app}/.worktrees/{$workspaceName}";
    mkdir($workspace, permissions: 0o755, recursive: true);

    return $workspace;
}

function delete_env_file_development_worktree_workspace(string $workspace): void
{
    if (is_file("{$workspace}/.env") || is_link("{$workspace}/.env")) {
        unlink("{$workspace}/.env");
    }

    if (is_dir($workspace)) {
        rmdir($workspace);
    }

    $worktrees = dirname($workspace);
    $app = dirname($worktrees);
    $apps = dirname($app);

    if (is_dir($worktrees)) {
        rmdir_if_empty($worktrees);
    }

    if (is_dir($app)) {
        rmdir_if_empty($app);
    }

    if (is_dir($apps) && basename($apps) === 'apps') {
        rmdir_if_empty($apps);
    }
}

function delete_env_file_codex_worktree_workspace(string $workspace): void
{
    if (is_file("{$workspace}/.env") || is_link("{$workspace}/.env")) {
        unlink("{$workspace}/.env");
    }

    if (is_dir($workspace)) {
        rmdir($workspace);
    }

    $parent = dirname($workspace);

    if (is_dir($parent)) {
        rmdir_if_empty($parent);
    }
}

function rmdir_if_empty(string $directory): void
{
    $entries = scandir($directory);

    if ($entries === false) {
        return;
    }

    $children = array_values(array_filter(
        $entries,
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
    ));

    if ($children === []) {
        rmdir($directory);
    }
}

function configureEnvFileOperationTokenGuard(): void
{
    app()->forgetInstance(\App\Services\Executor\OperationTokenGuard::class);
}

function envFileSignedOperationToken(
    string $id = 'env-file',
    string $node = 'app-dev',
    string $command = 'internal:env-file',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runInternalEnvFileCommand(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:env-file']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}
