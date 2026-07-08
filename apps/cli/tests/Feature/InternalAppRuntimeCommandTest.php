<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app runtime command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $home = getenv('HOME');
        $this->originalHome = is_string($home) ? $home : null;
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $this->originalEnvHome = $_ENV['HOME'] ?? null;
    });

    afterEach(function (): void {
        app_runtime_command_restore_home(
            $this->originalHome,
            $this->originalServerHome,
            $this->originalEnvHome,
        );

        $homeDirectories = glob(sys_get_temp_dir().'/orbit-app-runtime-home-*');

        foreach ($homeDirectories === false ? [] : $homeDirectories as $dir) {
            delete_app_runtime_command_home($dir);
        }
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command('container:apply', ['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid actions after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command('delete-everything', [
            '--operation-token' => app_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime action is invalid.',
                ['field' => 'action'],
            ));
    });

    it('validates container apply specs after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command(
            'container:apply',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'spec' => null,
                'runtime_config' => null,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime container spec is invalid.',
                ['field' => 'spec'],
            ));
    });

    it('rejects runtime config writes outside the managed Orbit config roots', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command(
            'runtime-config:write',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'runtime_config' => [
                    'path' => '/etc/sudoers',
                    'content_base64' => base64_encode('memory_limit=512M'),
                    'directories' => [],
                    'trust_pool' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime path is invalid.',
                ['field' => 'runtime_config.path'],
            ));
    });

    it('rejects arbitrary privileged runtime config directories', function (): void {
        $home = app_runtime_command_home();

        [$exitCode, $output] = run_internal_app_runtime_command(
            'runtime-config:write',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'runtime_config' => [
                    'path' => "{$home}/.config/orbit/apps/docs.ini",
                    'content_base64' => base64_encode('memory_limit=512M'),
                    'directories' => [
                        [
                            'path' => '/etc',
                            'mode' => '0755',
                            'owner' => null,
                            'group' => null,
                        ],
                    ],
                    'trust_pool' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime config directory is invalid.',
                ['field' => 'runtime_config.directories.path'],
            ));
    });

    it('writes user-owned runtime config files under the Orbit config root', function (): void {
        $home = app_runtime_command_home();

        [$exitCode, $output] = run_internal_app_runtime_command(
            'runtime-config:write',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'runtime_config' => [
                    'path' => "{$home}/.config/orbit/apps/docs.ini",
                    'content_base64' => base64_encode("memory_limit=512M\n"),
                    'directories' => [
                        [
                            'path' => "{$home}/.config/orbit/apps",
                            'mode' => '0755',
                            'owner' => null,
                            'group' => null,
                        ],
                    ],
                    'trust_pool' => [
                        'path' => "{$home}/.config/orbit/ca/root.crt",
                        'content_base64' => base64_encode("test-root\n"),
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'action' => 'runtime-config:write',
                'path' => "{$home}/.config/orbit/apps/docs.ini",
                'changed' => true,
            ]))
            ->and(file_get_contents("{$home}/.config/orbit/apps/docs.ini"))
            ->toBe("memory_limit=512M\n")
            ->and(file_get_contents("{$home}/.config/orbit/ca/root.crt"))
            ->toBe("test-root\n")
            ->and(substr(sprintf('%o', fileperms("{$home}/.config/orbit/apps/docs.ini")), -4))
            ->toBe('0644')
            ->and(substr(sprintf('%o', fileperms("{$home}/.config/orbit/ca/root.crt")), -4))
            ->toBe('0644');
    });

    it('allows managed user config paths when process HOME differs from the target user home', function (): void {
        $safeHome = app_runtime_command_safe_user_home($this->originalHome);

        if ($safeHome === null) {
            $this->markTestSkipped('Current HOME is not a safe /home or /Users path.');
        }

        assert($safeHome !== null, description: 'Safe user home is required after skip guard.');

        $processHome = app_runtime_command_home();
        $slug = 'orbit-test-'.bin2hex(random_bytes(8));
        $configPath = "{$safeHome['home']}/.config/orbit/apps/{$slug}.ini";

        try {
            [$exitCode, $output] = run_internal_app_runtime_command(
                'runtime-config:write',
                [
                    '--operation-token' => app_runtime_signed_operation_token(),
                    '--json' => true,
                ],
                stdin: json_encode([
                    'runtime_config' => [
                        'path' => $configPath,
                        'content_base64' => base64_encode("memory_limit=512M\n"),
                        'directories' => [
                            [
                                'path' => dirname($configPath),
                                'mode' => '0755',
                                'owner' => null,
                                'group' => null,
                            ],
                        ],
                        'trust_pool' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
            );

            expect($exitCode)
                ->toBe(0)
                ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
                ->toBe(JsonEnvelope::success([
                    'action' => 'runtime-config:write',
                    'path' => $configPath,
                    'changed' => true,
                ]))
                ->and($processHome)
                ->not
                ->toBe($safeHome['home'])
                ->and(file_get_contents($configPath))
                ->toBe("memory_limit=512M\n");
        } finally {
            if (file_exists($configPath)) {
                unlink($configPath);
            }
        }
    });

    it('creates same-user runtime mount directories without sudo', function (): void {
        $safeHome = app_runtime_command_safe_user_home($this->originalHome);

        if ($safeHome === null) {
            $this->markTestSkipped('Current HOME is not a safe /home or /Users path.');
        }

        assert($safeHome !== null, description: 'Safe user home is required after skip guard.');

        $home = app_runtime_command_home();
        $mountDirectory = $safeHome['home'].'/orbit-app-runtime-mount-'.bin2hex(random_bytes(8));
        $fakeBin = "{$home}/bin";
        $originalPath = getenv('PATH');

        mkdir($fakeBin);
        file_put_contents("{$fakeBin}/sudo", data: "#!/bin/sh\nexit 99\n");
        chmod("{$fakeBin}/sudo", permissions: 0o755);
        putenv('PATH='.$fakeBin.($originalPath === false ? '' : ":{$originalPath}"));

        try {
            [$exitCode, $output] = run_internal_app_runtime_command(
                'runtime-config:write',
                [
                    '--operation-token' => app_runtime_signed_operation_token(),
                    '--json' => true,
                ],
                stdin: json_encode([
                    'runtime_config' => [
                        'path' => "{$home}/.config/orbit/apps/docs.ini",
                        'content_base64' => base64_encode("memory_limit=512M\n"),
                        'directories' => [
                            [
                                'path' => "{$home}/.config/orbit/apps",
                                'mode' => '0755',
                                'owner' => null,
                                'group' => null,
                            ],
                            [
                                'path' => $mountDirectory,
                                'mode' => '0775',
                                'owner' => $safeHome['user'],
                                'group' => $safeHome['user'],
                            ],
                        ],
                        'trust_pool' => null,
                    ],
                ], JSON_THROW_ON_ERROR),
            );
        } finally {
            $originalPath === false ? putenv('PATH') : putenv("PATH={$originalPath}");
        }

        try {
            expect($exitCode)
                ->toBe(0)
                ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
                ->toBe(JsonEnvelope::success([
                    'action' => 'runtime-config:write',
                    'path' => "{$home}/.config/orbit/apps/docs.ini",
                    'changed' => true,
                ]))
                ->and(is_dir($mountDirectory))
                ->toBeTrue()
                ->and(substr(sprintf('%o', fileperms($mountDirectory)), -4))
                ->toBe('0775');
        } finally {
            delete_app_runtime_command_home($mountDirectory);
        }
    });
});

function app_runtime_signed_operation_token(
    string $id = 'app-runtime-container',
    string $node = 'app-dev',
    string $command = 'internal:app-runtime-container',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: implode('-', ['gateway', 'secret']),
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
function run_internal_app_runtime_command(string $action, array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput([
        'action' => $action,
        ...$parameters,
    ]);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:app-runtime-container'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal app runtime command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function app_runtime_command_home(): string
{
    $home = sys_get_temp_dir().'/orbit-app-runtime-home-'.bin2hex(random_bytes(8));
    mkdir($home);
    putenv("HOME={$home}");
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    return $home;
}

function app_runtime_command_restore_home(?string $home, ?string $serverHome, ?string $envHome): void
{
    $home === null ? putenv('HOME') : putenv("HOME={$home}");

    if ($serverHome === null) {
        unset($_SERVER['HOME']);
    }

    if ($serverHome !== null) {
        $_SERVER['HOME'] = $serverHome;
    }

    if ($envHome === null) {
        unset($_ENV['HOME']);
    }

    if ($envHome !== null) {
        $_ENV['HOME'] = $envHome;
    }
}

/**
 * @return array{home: string, user: string}|null
 */
function app_runtime_command_safe_user_home(?string $home): ?array
{
    if (! is_string($home)) {
        return null;
    }

    $home = rtrim($home, characters: '/');
    $matches = [];

    if (preg_match('#^/(?:home|Users)/(?<user>[A-Za-z0-9][A-Za-z0-9._-]*)$#', $home, $matches) !== 1) {
        return null;
    }

    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $user = \posix_getpwuid(\posix_geteuid());

        if (is_array($user) && $user['name'] !== $matches['user']) {
            return null;
        }
    }

    return [
        'home' => $home,
        'user' => $matches['user'],
    ];
}

function delete_app_runtime_command_home(string $path): void
{
    if (! is_dir($path)) {
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }

        return;
    }

    $entries = scandir($path);

    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        delete_app_runtime_command_home("{$path}/{$entry}");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}
