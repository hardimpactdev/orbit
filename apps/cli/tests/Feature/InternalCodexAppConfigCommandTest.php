<?php

declare(strict_types=1);

use App\Services\CodexApp\LocalCodexAppConfigAction;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @mago-expect lint:halstead
 */
describe('internal codex app config command', function (): void {
    beforeEach(function (): void {
        $this->originalCodexAppHome = getenv('HOME');
        $this->codexAppHome = sys_get_temp_dir().'/orbit-codex-app-config-'.bin2hex(random_bytes(8));
        mkdir($this->codexAppHome);
        putenv("HOME={$this->codexAppHome}");
        Process::fake(['*' => Process::result()]);
        Process::preventStrayProcesses();
        configure_codex_app_config_operation_token_guard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        app()->forgetInstance(Filesystem::class);
        File::deleteDirectory($this->codexAppHome);

        $this->originalCodexAppHome === false ? putenv('HOME') : putenv("HOME={$this->originalCodexAppHome}");
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_codex_app_config_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects invalid json payloads after token validation', function (): void {
        [$exitCode, $output] = run_internal_codex_app_config_command([
            '--operation-token' => codex_app_config_signed_operation_token(),
            '--json' => true,
        ], stdin: 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('validation_failed', 'Codex App config payload is invalid.'));
    });

    it('rejects unknown actions', function (): void {
        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'delete',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Codex App config action is invalid.');
    });

    it('serializes concurrent add and remove mutations without losing either update', function (): void {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required for concurrent Codex App config coverage.');
        }

        $configPath = codex_app_config_path($this->codexAppHome);
        codex_app_write_config($configPath, [
            'version' => 1,
            'remoteConnections' => [[
                'sshAlias' => 'old-node',
                'projects' => [[
                    'remotePath' => '/srv/old',
                    'label' => 'old',
                ]],
            ]],
        ]);

        $lock = fopen(filename: $configPath.'.lock', mode: 'c');

        expect($lock)->toBeResource()->and(flock($lock, LOCK_EX))->toBeTrue();

        $statusPaths = [
            $this->codexAppHome.'/add.status',
            $this->codexAppHome.'/remove.status',
        ];
        $payloads = [
            codex_app_mutation_payload(mutation: 'add', label: 'new', sshAlias: 'new-node', remotePath: '/srv/new'),
            codex_app_mutation_payload(mutation: 'remove', label: 'old', sshAlias: 'old-node'),
        ];
        $children = [];

        foreach ($payloads as $index => $payload) {
            $child = pcntl_fork();

            if ($child === -1) {
                fclose($lock);
                $this->fail('Unable to fork concurrent Codex App config mutation.');
            }

            if ($child === 0) {
                fclose($lock);

                try {
                    app(LocalCodexAppConfigAction::class)->run($payload);
                    file_put_contents(filename: $statusPaths[$index], data: 'ok');
                } catch (Throwable $exception) {
                    file_put_contents(
                        filename: $statusPaths[$index],
                        data: $exception::class.': '.$exception->getMessage(),
                    );
                }

                exit(0);
            }

            $children[] = $child;
        }

        usleep(100_000);

        expect(array_filter($statusPaths, is_file(...)))->toBeEmpty();

        flock($lock, LOCK_UN);
        fclose($lock);

        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }

        $config = json_decode((string) file_get_contents($configPath), associative: true, flags: JSON_THROW_ON_ERROR);

        expect(array_map(static fn (string $path): string => (string) file_get_contents($path), $statusPaths))
            ->toBe(['ok', 'ok'])
            ->and(codex_app_project_labels($config))
            ->toBe(['new']);
    });

    it('fails coherently when the mutation lock cannot be acquired', function (): void {
        $configPath = codex_app_config_path($this->codexAppHome);
        mkdir($configPath.'.lock', permissions: 0o700, recursive: true);

        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(
                codex_app_mutation_payload(
                    mutation: 'add',
                    label: 'docs',
                    sshAlias: 'app-node',
                    remotePath: '/srv/docs',
                ),
                JSON_THROW_ON_ERROR,
            ),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('codex_app.config_lock_failed')
            ->and(is_file($configPath))
            ->toBeFalse();

        Process::assertNothingRan();
    });

    it('preserves invalid config bytes and releases the lock after a read failure', function (): void {
        $configPath = codex_app_config_path($this->codexAppHome);
        $contents = "{not-json\n";
        codex_app_write_contents($configPath, $contents);

        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(
                codex_app_mutation_payload(
                    mutation: 'remove',
                    label: 'docs',
                    sshAlias: 'app-node',
                ),
                JSON_THROW_ON_ERROR,
            ),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('codex_app.config_read_failed')
            ->and(file_get_contents($configPath))
            ->toBe($contents)
            ->and(codex_app_lock_is_available($configPath.'.lock'))
            ->toBeTrue();

        Process::assertNothingRan();
    });

    it('does not replace an unchanged config and still applies it', function (): void {
        $configPath = codex_app_config_path($this->codexAppHome);
        $contents =
            json_encode([
                'version' => 1,
                'custom' => ['preserved' => true],
                'remoteConnections' => [[
                    'sshAlias' => 'app-node',
                    'projects' => [[
                        'remotePath' => '/srv/docs',
                        'label' => 'docs',
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        codex_app_write_contents($configPath, $contents);
        $inode = fileinode($configPath);

        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(
                codex_app_mutation_payload(
                    mutation: 'add',
                    label: 'docs',
                    sshAlias: 'app-node',
                    remotePath: '/srv/docs',
                ),
                JSON_THROW_ON_ERROR,
            ),
        );

        clearstatcache(true, $configPath);
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['changed'] ?? null)
            ->toBeFalse()
            ->and(file_get_contents($configPath))
            ->toBe($contents)
            ->and(fileinode($configPath))
            ->toBe($inode);

        Process::assertRan(fn ($process): bool => $process->command === ['open', 'codex://codex-app/apply-config']);
    });

    it('preserves the committed config and cleans up the lock after a write or replacement failure', function (string $method): void {
        $configPath = codex_app_config_path($this->codexAppHome);
        $contents = "{\n    \"version\": 1,\n    \"preserved\": true\n}\n";
        codex_app_write_contents($configPath, $contents);
        $filesystem = Mockery::mock(Filesystem::class)->makePartial();
        $filesystem->shouldReceive($method)->once()->andReturnFalse();
        app()->instance(Filesystem::class, $filesystem);

        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(
                codex_app_mutation_payload(
                    mutation: 'add',
                    label: 'docs',
                    sshAlias: 'app-node',
                    remotePath: '/srv/docs',
                ),
                JSON_THROW_ON_ERROR,
            ),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('codex_app.config_write_failed')
            ->and(file_get_contents($configPath))
            ->toBe($contents)
            ->and(glob($configPath.'.tmp.*'))
            ->toBeEmpty()
            ->and(codex_app_lock_is_available($configPath.'.lock'))
            ->toBeTrue();

        Process::assertNothingRan();
    })->with([
        'temporary write' => 'put',
        'atomic replacement' => 'move',
    ]);

    it('commits the config, warns on apply failure, and releases the lock', function (): void {
        Process::fake([
            '*' => Process::result(errorOutput: 'callback unavailable', exitCode: 1),
        ]);
        $configPath = codex_app_config_path($this->codexAppHome);

        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(
                codex_app_mutation_payload(
                    mutation: 'add',
                    label: 'docs',
                    sshAlias: 'app-node',
                    remotePath: '/srv/docs',
                ),
                JSON_THROW_ON_ERROR,
            ),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode((string) file_get_contents($configPath), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['meta']['warnings'][0] ?? null)
            ->toBe([
                'code' => 'codex_app.apply_failed',
                'message' => 'Codex App config apply callback failed.',
                'meta' => [
                    'exit_code' => 1,
                    'stderr' => 'callback unavailable',
                ],
            ])
            ->and(codex_app_project_labels($config))
            ->toBe(['docs'])
            ->and(codex_app_lock_is_available($configPath.'.lock'))
            ->toBeTrue();
    });

    it('keeps the committed config and warning contract when apply cannot start', function (): void {
        Process::fake(static fn (): mixed => throw new RuntimeException('open is unavailable'));
        $configPath = codex_app_config_path($this->codexAppHome);

        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(
                codex_app_mutation_payload(
                    mutation: 'add',
                    label: 'docs',
                    sshAlias: 'app-node',
                    remotePath: '/srv/docs',
                ),
                JSON_THROW_ON_ERROR,
            ),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['meta']['warnings'][0]['meta'] ?? null)
            ->toBe([
                'exit_code' => null,
                'stderr' => 'open is unavailable',
            ])
            ->and(is_file($configPath))
            ->toBeTrue()
            ->and(codex_app_lock_is_available($configPath.'.lock'))
            ->toBeTrue();
    });
});

/**
 * @return array{action: string, mutation: string, project: array{label: string, ssh_alias: string, remote_path?: string}}
 */
function codex_app_mutation_payload(
    string $mutation,
    string $label,
    string $sshAlias,
    ?string $remotePath = null,
): array {
    $project = [
        'label' => $label,
        'ssh_alias' => $sshAlias,
    ];

    if ($remotePath !== null) {
        $project['remote_path'] = $remotePath;
    }

    return [
        'action' => 'mutate',
        'mutation' => $mutation,
        'project' => $project,
    ];
}

function codex_app_config_path(string $home): string
{
    return $home.'/.codex/codex-app/config.json';
}

/**
 * @param  array<string, mixed>  $config
 */
function codex_app_write_config(string $path, array $config): void
{
    codex_app_write_contents(
        $path,
        json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n",
    );
}

function codex_app_write_contents(string $path, string $contents): void
{
    mkdir(dirname($path), permissions: 0o700, recursive: true);
    file_put_contents($path, $contents);
}

/**
 * @param  array<string, mixed>  $config
 * @return list<string>
 */
function codex_app_project_labels(array $config): array
{
    $labels = [];

    foreach ($config['remoteConnections'] ?? [] as $connection) {
        foreach ($connection['projects'] ?? [] as $project) {
            $labels[] = $project['label'];
        }
    }

    sort($labels);

    return $labels;
}

function codex_app_lock_is_available(string $path): bool
{
    $handle = fopen(filename: $path, mode: 'c');

    if ($handle === false) {
        return false;
    }

    try {
        return flock($handle, LOCK_EX | LOCK_NB);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function configure_codex_app_config_operation_token_guard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function codex_app_config_signed_operation_token(
    string $id = 'codex-app-config',
    string $node = 'agent',
    string $command = 'internal:codex-app-config',
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
function run_internal_codex_app_config_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:codex-app-config']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}
