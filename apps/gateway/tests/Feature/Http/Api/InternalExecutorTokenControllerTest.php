<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenCommandContext;
use Tests\TestCase;

uses(RefreshDatabase::class);

const INTERNAL_EXECUTOR_TOKEN_CALLER_WG_IP = '10.6.0.50';

describe('InternalExecutorTokenController', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.trust_wireguard_proxy_header', true);
        config()->set('app.key', 'gateway-app-key');
        config()->set('orbit.operation_token_secret', 'operation-token-secret');
        config()->set('orbit.operation_token_key_id', 'current');
        config()->set('orbit.operation_token_previous_secret', null);
        config()->set('orbit.operation_token_previous_key_id', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenFactory::class);
        app()->forgetInstance(OperationTokenIntrospector::class);
    });

    it('returns a successful introspection result for a valid token', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('rejects a valid token when the agent-presented argv differs from the signed context', function (): void {
        internalExecutorCallerNode();
        internal_executor_operation_run('operation-argv-tamper');

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-argv-tamper',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
            commandContext: internal_executor_trusted_command_context(),
        );

        internalExecutorVerifyTokenRequest(internal_executor_token_payload(
            operationToken: $operationToken->toString(),
            argv: [
                'internal:executor:verify',
                '--mode=tampered',
                '--operation-token='.$operationToken->toString(),
                '--json',
            ],
        ))
            ->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'arguments_mismatch')
            ->assertJsonPath('success.data.operation_id', 'operation-argv-tamper');
    });

    it('consumes a valid operation token at verify time and rejects a duplicate verify distinctly', function (): void {
        internalExecutorCallerNode();
        $operationId = (string) Str::uuid();
        internal_executor_operation_run($operationId);

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: $operationId,
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
            commandContext: internal_executor_trusted_command_context(),
        );
        $payload = internal_executor_token_payload($operationToken->toString());

        internalExecutorVerifyTokenRequest($payload)
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', $operationId);

        internalExecutorVerifyTokenRequest($payload)
            ->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'operation.already_dispatched')
            ->assertJsonPath('success.data.operation_id', $operationId);

        $run = DB::table('operation_runs')->where('operation_id', $operationId)->first();

        expect($run->operation_token_consumed_at)->not->toBeNull();
    });

    it('consumes only the exact attempt when sibling runs share a logical operation id', function (): void {
        internalExecutorCallerNode();
        $logicalOperationId = (string) Str::uuid();
        $firstAttemptId = (string) Str::uuid();
        $secondAttemptId = (string) Str::uuid();
        internal_executor_operation_run($logicalOperationId, $firstAttemptId);
        internal_executor_operation_run($logicalOperationId, $secondAttemptId);

        foreach ([$firstAttemptId, $secondAttemptId] as $attemptId) {
            $operationToken = app(OperationTokenFactory::class)->mint(
                operationId: $attemptId,
                targetNode: 'app-dev',
                command: 'internal:executor:verify',
            );

            internalExecutorVerifyTokenRequest([
                'operation_token' => $operationToken->toString(),
                'command' => 'internal:executor:verify',
            ])
                ->assertOk()
                ->assertJsonPath('success.data.allowed', true)
                ->assertJsonPath('success.data.operation_id', $attemptId);
        }

        expect(
            DB::table('operation_runs')
                ->whereIn('id', [$firstAttemptId, $secondAttemptId])
                ->whereNotNull('operation_token_consumed_at')
                ->count(),
        )
            ->toBe(2);
    });

    it('returns invalid_token for malformed tokens', function (): void {
        internalExecutorCallerNode();

        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'internal:executor:verify',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'invalid_token')
            ->assertJsonPath('success.data.operation_id', null);
    });

    it('returns target_node_mismatch for tokens minted for another node', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-prod',
            command: 'internal:executor:verify',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'target_node_mismatch')
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('returns command_mismatch for a different internal command', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-dev',
            command: 'internal:executor:status',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'command_mismatch')
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('accepts the agent binary version proof command', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-dev',
            command: 'version',
        );

        internalExecutorVerifyTokenRequest([
            'operation_token' => $operationToken->toString(),
            'command' => 'version',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('accepts gateway self verification over loopback for runtime backend probes', function (): void {
        internal_executor_gateway_node();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'gateway',
            command: 'internal:runtime-backend:probe',
        );

        internal_executor_verify_token_request_from_address([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:runtime-backend:probe',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('accepts gateway self verification over alternate IPv4 loopback addresses', function (): void {
        internal_executor_gateway_node();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'gateway',
            command: 'internal:runtime-backend:probe',
        );

        internal_executor_verify_token_request_from_address([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:runtime-backend:probe',
        ], remoteAddress: '127.0.1.1')
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('accepts gateway self verification from service network addresses and rejects replay in the controller', function (): void {
        internal_executor_gateway_node();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'gateway',
            command: 'internal:fleet-update:install-cli',
        );
        $payload = [
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:fleet-update:install-cli',
        ];

        internal_executor_verify_token_request_from_address($payload, remoteAddress: '172.18.0.1')
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');

        internal_executor_verify_token_request_from_address($payload, remoteAddress: '172.18.0.1')
            ->assertOk()
            ->assertJsonPath('success.data.allowed', false)
            ->assertJsonPath('success.data.reason', 'operation.already_dispatched')
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('does not consume gateway tokens when service network identity binding fails', function (): void {
        internal_executor_gateway_node();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'gateway',
            command: 'internal:fleet-update:install-cli',
        );

        internal_executor_verify_token_request_from_address([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:fleet-update:install-cli',
            'argv' => [
                'internal:fleet-update:install-cli',
                '--tampered',
                '--operation-token='.$operationToken->toString(),
            ],
        ], remoteAddress: '172.18.0.1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');

        internal_executor_verify_token_request_from_address([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:fleet-update:install-cli',
        ], remoteAddress: '172.18.0.1')
            ->assertOk()
            ->assertJsonPath('success.data.allowed', true)
            ->assertJsonPath('success.data.reason', null)
            ->assertJsonPath('success.data.operation_id', 'operation-123');
    });

    it('does not use gateway token verification as identity for non-gateway nodes', function (): void {
        internalExecutorCallerNode();

        $operationToken = app(OperationTokenFactory::class)->mint(
            operationId: 'operation-123',
            targetNode: 'app-dev',
            command: 'internal:executor:verify',
        );

        internal_executor_verify_token_request_from_address([
            'operation_token' => $operationToken->toString(),
            'command' => 'internal:executor:verify',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('rejects non-internal commands at validation time', function (): void {
        internalExecutorCallerNode();

        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'deploy:run',
        ])
            ->assertUnprocessable()
            ->assertInvalid(['command']);
    });

    it('rejects unknown peers before reaching the controller', function (): void {
        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'internal:executor:verify',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('rejects inactive peers before reaching the controller', function (): void {
        internalExecutorCallerNode([
            'status' => 'inactive',
        ]);

        internalExecutorVerifyTokenRequest([
            'operation_token' => 'not-a-token',
            'command' => 'internal:executor:verify',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});

function internalExecutorCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'app-dev',
        'status' => 'active',
        'wireguard_address' => INTERNAL_EXECUTOR_TOKEN_CALLER_WG_IP,
    ], $overrides));
}

function internal_executor_gateway_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'gateway',
        'status' => 'active',
        'wireguard_address' => '10.6.0.1',
    ]);

    foreach (['gateway', 'metrics'] as $role) {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    return $node;
}

function internal_executor_operation_run(string $operationId, ?string $attemptId = null): void
{
    $attemptId ??= $operationId;

    if (DB::table('operation_runs')->where('id', $attemptId)->exists()) {
        return;
    }

    DB::table('operation_runs')->insert([
        'id' => $attemptId,
        'operation_id' => $operationId,
        'internal_command' => 'internal:executor:verify',
        'lane' => 'local',
        'status' => OperationStatus::Running->value,
        'started_at' => now(),
        'operation_token_consumed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function internal_executor_trusted_command_context(): OperationTokenCommandContext
{
    return OperationTokenCommandContext::fromTrustedDispatch(
        argv: [
            'internal:executor:verify',
            '--mode=normal',
            '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
            '--json',
        ],
        cwd: '/srv/orbit',
        environment: ['ALPHA' => '1', 'BETA' => '2'],
        input: 'stdin-payload',
    );
}

/**
 * @return array<string, mixed>
 */
function internal_executor_token_payload(
    #[SensitiveParameter]
    string $operationToken,
    ?array $argv = null,
): array {
    return [
        'operation_token' => $operationToken,
        'command' => 'internal:executor:verify',
        'argv' => $argv ?? [
            'internal:executor:verify',
            '--mode=normal',
            '--operation-token='.$operationToken,
            '--json',
        ],
        'cwd' => '/srv/orbit',
        'environment' => ['BETA' => '2', 'ALPHA' => '1'],
        'input' => 'stdin-payload',
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function internalExecutorVerifyTokenRequest(#[SensitiveParameter] array $payload)
{
    /** @var TestCase $test */
    $test = test();
    $payload = internal_executor_normalize_token_payload($payload);

    return $test
        ->withHeader('X-Orbit-WireGuard-Ip', INTERNAL_EXECUTOR_TOKEN_CALLER_WG_IP)
        ->postJson('/api/internal-executor/token/verify', $payload);
}

/**
 * @param  array<string, mixed>  $payload
 */
function internal_executor_verify_token_request_from_address(
    #[SensitiveParameter]
    array $payload,
    string $remoteAddress = '127.0.0.1',
) {
    /** @var TestCase $test */
    $test = test();
    $payload = internal_executor_normalize_token_payload($payload);

    return $test
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->postJson('/api/internal-executor/token/verify', $payload);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function internal_executor_normalize_token_payload(#[SensitiveParameter] array $payload): array
{
    if (
        ! array_key_exists('argv', $payload)
        && array_key_exists('operation_token', $payload)
        && array_key_exists('command', $payload)
        && is_string($payload['operation_token'])
        && is_string($payload['command'])
    ) {
        $payload['argv'] = [
            $payload['command'],
            '--operation-token='.$payload['operation_token'],
        ];
    }

    if (array_key_exists('operation_token', $payload) && is_string($payload['operation_token'])) {
        try {
            $token = OperationToken::parse($payload['operation_token']);
            internal_executor_operation_run($token->id);
        } catch (InvalidArgumentException) {
            return $payload;
        }
    }

    return $payload;
}
