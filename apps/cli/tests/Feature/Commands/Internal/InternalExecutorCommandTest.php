<?php

declare(strict_types=1);

use App\Commands\Internal\InternalExecutorCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
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
    config()->set('orbit.executor.shared_secret', 'test-secret-key');
    config()->set('orbit.executor.node_identity', 'test-node');
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
        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => 'not-a-valid-token',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
    });

    it('accepts a valid operation token and emits success', function (): void {
        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data']['verified'])->toBeTrue();
    });

    it('outputs human-readable result for a valid token without --json', function (): void {
        [$exitCode, $output] = runTestInternalExecutorCommand($this, [
            '--operation-token' => signInternalExecutorToken(),
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('verified: true');
    });
});
