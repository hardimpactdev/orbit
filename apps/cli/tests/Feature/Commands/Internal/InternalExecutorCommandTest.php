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

    return (new OperationTokenSigner)->sign(
        secret: 'test-secret-key',
        id: $id,
        node: $node,
        command: $command,
        issuedAt: $issuedAt,
        expiresAt: $expiresAt,
    )->toString();
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

describe('InternalExecutorCommand base', function (): void {
    beforeEach(function (): void {
        configureInternalExecutorTestGuard();
    });

    it('rejects a missing operation token with missing_token code', function (): void {
        [$exitCode, $output] = runTestInternalExecutorCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects an empty operation token with missing_token code', function (): void {
        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => '',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
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

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
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

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['verified'])->toBeTrue();

        Http::assertSent(function (Request $request) use ($token): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                && $request['operation_token'] === $token
                && $request['command'] === 'test:internal-executor-command';
        });
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

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data']['verified'])->toBeTrue();
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

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)->not->toContain('allowed')
            ->and($output)->not->toContain('gateway');
    });

    it('maps gateway transport failures to invalid_token without leaking details', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)->not->toContain('No route to host');
    });

    it('accepts a locally valid operation token when the gateway verifier transport fails', function (): void {
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

            expect($exitCode)->toBe(0)
                ->and($decoded)->toBe(JsonEnvelope::success([
                    'verified' => true,
                    'command' => 'test:internal-executor-command',
                ]));
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

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('verified: true');
    });

    it('does not call the gateway when the token is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runTestInternalExecutorCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));

        Http::assertNothingSent();
    });
});
