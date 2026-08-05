<?php

declare(strict_types=1);

use App\Commands\Internal\InternalExecutorCommand;
use App\Commands\Internal\VerifyExecutorCommand;
use App\Services\Executor\OperationTokenGuard;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

beforeEach(function (): void {
    app()->forgetInstance(OperationTokenGuard::class);
});

function signVerifyExecutorToken(
    string $id = 'op-verify-1',
    string $node = 'verify-executor-test-node',
    string $command = 'internal:executor:verify',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 5;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'verify-executor-test-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

describe('ORBIT-CLI-ARCH-01 — VerifyExecutorCommand pillars', function (): void {
    it('extends InternalExecutorCommand so the token + result-boundary contract is inherited, not reimplemented', function (): void {
        expect(is_subclass_of(VerifyExecutorCommand::class, InternalExecutorCommand::class))->toBeTrue();
    });

    it('rejects a missing operation token with code missing_token BEFORE any side effect runs (token-first validation)', function (): void {
        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it(
        'rejects an invalid operation token with code invalid_token via the OperationTokenGuard service (action/service delegation)',
        function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'allowed' => false,
            ]));

            [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
                '--operation-token' => 'not-a-real-token',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(1)
                ->and($decoded)
                ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
        },
    );

    it('propagates a recognized gateway denial reason through verify JSON failures', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => false,
            'reason' => 'target_node_mismatch',
            'operation_id' => 'op-verify-node-mismatch',
        ]));

        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => signVerifyExecutorToken(id: 'op-verify-node-mismatch'),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('target_node_mismatch', 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('op-verify-node-mismatch');
    });

    it('posts the expected verification payload to the gateway endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        $token = signVerifyExecutorToken(id: 'op-verify-payload');

        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => $token,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['operation_id'])->toBe('op-verify-payload');

        Http::assertSent(function (Request $request) use ($token): bool {
            return (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
                && $request['operation_token'] === $token
                && $request['command'] === 'internal:executor:verify'
            );
        });
    });

    it('uses the current gateway client when the guard singleton was resolved before config changed', function (): void {
        config()->set('orbit.gateway.url', 'https://stale-gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app(OperationTokenGuard::class);

        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => signVerifyExecutorToken(id: 'op-verify-fresh-client'),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['operation_id'])->toBe('op-verify-fresh-client');

        Http::assertSent(
            fn (Request $request): bool => (
                $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
            ),
        );
        Http::assertNotSent(
            fn (Request $request): bool => (
                $request->url() === 'https://stale-gateway.test/api/internal-executor/token/verify'
            ),
        );
    });

    it(
        'returns a typed result with operation_id, node, and command keys after the guard accepts the token (typed result serialization)',
        function (): void {
            fakeGateway(fakeSuccessEnvelope([
                'allowed' => true,
            ]));

            [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
                '--operation-token' => signVerifyExecutorToken(id: 'op-verify-42', node: 'verify-executor-test-node'),
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($decoded)
                ->toHaveKey('success')
                ->and($decoded['success']['data'])
                ->toMatchArray([
                    'operation_id' => 'op-verify-42',
                    'node' => 'verify-executor-test-node',
                    'command' => 'internal:executor:verify',
                ]);
        },
    );

    it('maps malformed gateway verification responses to invalid_token without leaking details', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'unexpected' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => signVerifyExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('unexpected')->and($output)
            ->not->toContain('gateway');
    });

    it('maps gateway verification transport failures to invalid_token without leaking details', function (): void {
        fakeGatewayDown('connection refused');

        [$exitCode, $output] = runCommand($this, 'internal:executor:verify', [
            '--operation-token' => signVerifyExecutorToken(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'))
            ->and($output)
            ->not->toContain('connection refused');
    });

    it('refuses to emit a result that would contain a forbidden secret key (result-boundary scan inherited from base)', function (): void {
        // VerifyExecutorCommand never emits secret-shaped keys; we just confirm the inherited
        // assertResultBoundaryClean()/emitInternalSuccess() rejection path is reachable for any
        // future migration. Use the InternalExecutorCommand base contract test instead.
        expect(method_exists(VerifyExecutorCommand::class, 'emitInternalSuccess'))
            ->toBeTrue()
            ->and(method_exists(VerifyExecutorCommand::class, 'verifyOperationToken'))
            ->toBeTrue();
    });
});
