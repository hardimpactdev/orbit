<?php

declare(strict_types=1);

use App\Commands\Internal\InternalExecutorCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Security\OperationTokenSigner;

/**
 * Redaction-focused test command that emits whatever $resultData is set to.
 */
class TestRedactionCommand extends InternalExecutorCommand
{
    protected $signature = 'test:redaction-command {--operation-token=} {--json}';

    protected $description = 'Test InternalExecutorCommand redaction';

    /** @var array<string, mixed> */
    public array $resultData = [];

    public function handle(): int
    {
        if (! $this->verifyOperationToken('test:redaction-command')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess($this->resultData);
    }
}

function configureRedactionTestGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function signRedactionToken(): string
{
    return (new OperationTokenSigner)->sign(
        secret: 'redaction-secret',
        id: 'redaction-op-1',
        node: 'redaction-node',
        command: 'test:redaction-command',
        issuedAt: time() - 5,
        expiresAt: time() + 120,
    )->toString();
}

/**
 * @param  array<string, mixed>  $resultData
 * @return array{int, string}
 */
function runRedactionCommand(object $test, array $resultData): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $command = new TestRedactionCommand;
    $command->resultData = $resultData;

    app(Kernel::class)->registerCommand($command);

    $exitCode = $test->artisan('test:redaction-command', [
        '--operation-token' => signRedactionToken(),
        '--json' => true,
    ]);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

describe('InternalExecutorCommand redaction', function (): void {
    beforeEach(function (): void {
        configureRedactionTestGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('blocks result containing key "operation_token"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, [
            'operation_token' => 'some-value',
            'other' => 'ok',
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret')
            ->and($decoded['error']['meta']['field'])->toBe('operation_token');
    });

    it('blocks result containing key "executor_secret"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['executor_secret' => 'secret-val']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing key "password"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['password' => 'hunter2']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing key "bearer"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['bearer' => 'token123']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing key "secret"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['secret' => 'my-secret']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing key "_token"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['_token' => 'csrf-val']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing key "api_key"', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['api_key' => 'key-value']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing key matching case-insensitively (PASSWORD)', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, ['PASSWORD' => 'val']);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret');
    });

    it('blocks result containing a PEM block value', function (): void {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7\n-----END PRIVATE KEY-----";

        [$exitCode, $output] = runRedactionCommand($this, ['key_data' => $pem]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret')
            ->and($decoded['error']['meta']['field'])->toBe('key_data');
    });

    it('blocks nested result containing a forbidden key', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, [
            'node' => 'app-1',
            'config' => [
                'database' => 'test',
                'password' => 'db-pass',
            ],
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret')
            ->and($decoded['error']['meta']['field'])->toBe('config.password');
    });

    it('blocks result containing key "api_token" (pattern-equivalent via _token substring match)', function (): void {
        // Demonstrates regression fix: api_token (and similar token-like secret fields) must be redacted
        // even though not an exact key in the base list; substring match against _token catches it.
        [$exitCode, $output] = runRedactionCommand($this, [
            'node' => 'app-1',
            'status' => 'active',
            'api_token' => 'tok-secret-should-be-redacted',
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret')
            ->and($decoded['error']['meta']['field'])->toBe('api_token');
    });

    it('blocks camelCase result keys matching forbidden patterns after normalization', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, [
            'node' => 'app-1',
            'apiKey' => 'key-secret-should-be-redacted',
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('result_contains_secret')
            ->and($decoded['error']['meta']['field'])->toBe('apiKey');
    });

    it('allows results with non-secret keys containing token substrings', function (): void {
        [$exitCode, $output] = runRedactionCommand($this, [
            'token_name' => 'some-opaque-value-not-pem',
            'description' => 'This config uses a token',
        ]);

        // 'token_name' does not contain any forbidden substring from the documented list
        // (e.g. does not contain "_token"), value is not PEM. Safe to allow.
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);
    });
});
