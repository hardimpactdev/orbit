<?php

declare(strict_types=1);

use App\Commands\Internal\InternalExecutorCommand;
use App\Services\OrbitConfigStore;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Minimal concrete command for testing the InternalExecutorCommand base.
 */
class TestInternalExecutorCommand extends InternalExecutorCommand
{
    protected $signature = 'test:internal-executor-command {--operation-token=} {--json}';

    protected $description = 'Test InternalExecutorCommand base';

    public function handle(): int
    {
        if (! $this->verifyOperationToken('test:internal-executor-command')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess(['verified' => true, 'command' => 'test:internal-executor-command']);
    }
}

/**
 * Echoes buffered stdin after token verification for payload-binding regression coverage.
 */
class TestInternalExecutorStdinCommand extends InternalExecutorCommand
{
    protected $signature = 'test:internal-executor-stdin {--operation-token=} {--json}';

    protected $description = 'Test InternalExecutorCommand stdin buffer binding';

    public function handle(): int
    {
        if (! $this->verifyOperationToken('test:internal-executor-stdin')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess([
            'stdin' => $this->stdin(),
            'stdin_again' => $this->stdin(),
        ]);
    }
}

function configureInternalExecutorTestGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function signInternalExecutorToken(
    string $id = 'test-op-1',
    string $node = 'test-node',
    string $command = 'test:internal-executor-command',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 5;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'test-secret-key',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runTestInternalExecutorCommand(object $test, array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    app(Kernel::class)->registerCommand(new TestInternalExecutorCommand);

    $exitCode = $test->artisan('test:internal-executor-command', $params);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

/** @mago-expect lint:cyclomatic-complexity */
describe('InternalExecutorCommand base', function (): void {
    beforeEach(function (): void {
        configureInternalExecutorTestGuard();
    });

    it('rejects a missing operation token with missing_token code', function (): void {
        [$exitCode, $output] = runTestInternalExecutorCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects an empty operation token with missing_token code', function (): void {
        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => '',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects an invalid token with invalid_token code', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
        ]));

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => 'not-a-valid-token',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
    });

    it('propagates recognized gateway denial reasons into the CLI failure code', function (string $reason): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
            'reason' => $reason,
            'operation_id' => 'op-safe-reason',
        ]));

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(id: 'op-safe-reason'),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure($reason, 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('operation_id')->and($output)
            ->not->toContain('op-safe-reason');
    })->with([
        'invalid_token',
        'arguments_mismatch',
        'target_node_mismatch',
        'command_mismatch',
        'operation.already_dispatched',
        'operation.not_found',
    ]);

    it('keeps unknown gateway denial reasons generic and redacted in JSON failures', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
            'reason' => 'secret_path_/etc/orbit/key.pem',
            'operation_id' => 'op-unsafe-reason',
        ]));

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(id: 'op-unsafe-reason'),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('secret_path')->and($output)
            ->not->toContain('/etc/orbit/key.pem')->and($output)
            ->not->toContain('op-unsafe-reason');
    });

    it('posts the compact token and expected command to the gateway verifier', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $token = signInternalExecutorToken();

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => $token,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['verified'])->toBeTrue();

        Http::assertSent(function (Request $request) use ($token): bool {
            return (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                && $request['operation_token'] === $token
                && $request['command'] === 'test:internal-executor-command'
                && is_array($request['argv'])
                && in_array('--operation-token='.$token, $request['argv'], strict: true)
                && $request['consume'] === true
            );
        });
    });

    it('includes the install metadata path in the gateway verifier environment', function (): void {
        $previousMetadataPath = getenv('ORBIT_INSTALL_METADATA_PATH');
        $metadataPath = '/home/orbit/.config/orbit/install.json';

        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        putenv("ORBIT_INSTALL_METADATA_PATH={$metadataPath}");

        try {
            $token = signInternalExecutorToken();

            [$exitCode, $output] = runTestInternalExecutorCommand($this, [
                '--operation-token' => $token,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)->and($decoded['success']['data']['verified'])->toBeTrue();

            Http::assertSent(function (Request $request) use ($metadataPath): bool {
                return (
                    $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                    && ($request['environment']['ORBIT_INSTALL_METADATA_PATH'] ?? null) === $metadataPath
                );
            });
        } finally {
            if ($previousMetadataPath === false) {
                putenv('ORBIT_INSTALL_METADATA_PATH');
            } else {
                putenv("ORBIT_INSTALL_METADATA_PATH={$previousMetadataPath}");
            }
        }
    });

    it('includes the wg-easy database path in the gateway verifier environment', function (): void {
        $previousDatabasePath = getenv('ORBIT_WG_EASY_DB_PATH');
        $databasePath = '/home/orbit/.config/orbit/wg-easy/wg-easy.db';

        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        putenv("ORBIT_WG_EASY_DB_PATH={$databasePath}");

        try {
            [$exitCode] = runTestInternalExecutorCommand($this, [
                '--operation-token' => signInternalExecutorToken(),
                '--json' => true,
            ]);

            expect($exitCode)->toBe(0);

            Http::assertSent(function (Request $request) use ($databasePath): bool {
                return (
                    $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                    && ($request['environment']['ORBIT_WG_EASY_DB_PATH'] ?? null) === $databasePath
                );
            });
        } finally {
            if ($previousDatabasePath === false) {
                putenv('ORBIT_WG_EASY_DB_PATH');
            } else {
                putenv("ORBIT_WG_EASY_DB_PATH={$previousDatabasePath}");
            }
        }
    });

    it('accepts a valid operation token and emits success', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toHaveKey('success')
            ->and($decoded['success']['data']['verified'])
            ->toBeTrue();
    });

    it('accepts a gateway-authorized trusted execution context without re-verifying reconstructed container state', function (): void {
        $token = signInternalExecutorToken(id: 'gateway-local-operation');
        $environment = [
            'ORBIT_TRUSTED_EXECUTION_LANE' => 'gateway-local',
            'ORBIT_TRUSTED_EXECUTION_OPERATION_ID' => 'gateway-local-operation',
            'ORBIT_TRUSTED_EXECUTION_COMMAND' => 'test:internal-executor-command',
            'ORBIT_TRUSTED_EXECUTION_OPERATION_TOKEN' => $token,
        ];
        $previous = [];

        foreach ($environment as $key => $value) {
            $previous[$key] = getenv($key);
            putenv("{$key}={$value}");
        }

        fakeGatewayDown('The gateway must not be called for a parent-authorized gateway-local command.');

        try {
            [$exitCode, $output] = runTestInternalExecutorCommand($this, [
                '--operation-token' => $token,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data'] ?? null)
                ->toMatchArray([
                    'verified' => true,
                    'command' => 'test:internal-executor-command',
                ]);

            Http::assertNothingSent();
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    });

    it('maps a malformed gateway response to invalid_token without leaking details', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => 'yes',
        ]));

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('allowed')->and($output)
            ->not->toContain('gateway');
    });

    it('maps gateway transport failures to invalid_token without leaking details', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('No route to host');
    });

    it('fails closed when the gateway verifier transport fails because tokens are gateway verified', function (): void {
        $previousAppKey = getenv('APP_KEY');
        $configPath = base_path('tests/.tmp-internal-executor-local-config.json');

        @unlink($configPath);

        fakeGatewayDown('No route to host');
        config()->set('app.key', null);
        putenv('APP_KEY=test-secret-key');

        $store = new OrbitConfigStore(overridePath: $configPath);
        $store->setDefaultNode('test-node');

        app()->instance(OrbitConfigStore::class, $store);
        configureInternalExecutorTestGuard();

        try {
            [$exitCode, $output] = runTestInternalExecutorCommand($this, [
                '--operation-token' => signInternalExecutorToken(),
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded)
                ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
        } finally {
            @unlink($configPath);

            if ($previousAppKey === false) {
                putenv('APP_KEY');
            } else {
                putenv("APP_KEY={$previousAppKey}");
            }
        }
    });

    it('outputs human-readable result for a valid token without --json', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
        ]);

        expect($exitCode)->toBe(0)->and($output)->toContain('verified: true');
    });

    it('does not call the gateway when the token is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runTestInternalExecutorCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));

        Http::assertNothingSent();
    });

    it('binds the same stdin payload for token verification and the handler after capture', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $boundInput = json_encode([
            'container' => 'orbit-caddy',
            'action' => 'apply',
            'marker' => 'post-verify-payload',
        ], JSON_THROW_ON_ERROR);

        $token = signInternalExecutorToken(command: 'test:internal-executor-stdin');

        [$exitCode, $output] = run_test_internal_executor_stdin_command([
            '--operation-token' => $token,
            '--json' => true,
        ], $boundInput);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['stdin'] ?? null)
            ->toBe($boundInput)
            ->and($decoded['success']['data']['stdin_again'] ?? null)
            ->toBe($boundInput);

        Http::assertSent(static function (Request $request) use ($token, $boundInput): bool {
            $operationToken = $request['operation_token'] ?? null;
            $input = $request['input'] ?? null;

            return (
                $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                && is_string($operationToken)
                && hash_equals($token, $operationToken)
                && ($request['command'] ?? null) === 'test:internal-executor-stdin'
                && is_string($input)
                && hash_equals($boundInput, $input)
            );
        });
    });
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_test_internal_executor_stdin_command(array $parameters = [], string $stdin = ''): array
{
    $command = new TestInternalExecutorStdinCommand;
    app(Kernel::class)->registerCommand($command);

    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}
